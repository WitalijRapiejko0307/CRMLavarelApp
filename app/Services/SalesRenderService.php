<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PhoneNormalizer;
use App\Support\SrResult;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SalesRender GraphQL client.
 * Mirrors GAS backend/SalesRender.gs.
 *
 * Two operations:
 *   sendOrder()   — push a new lead to SalesRender (statusId=1), returns SR order ID
 *   fetchOrder()  — pull order data by SR order ID (used by SyncSalesRenderJob)
 *
 * Required tenant_settings keys:
 *   api_token_call_centr       — Bearer token for SalesRender API
 *   company_id_in_call_centre  — Company ID (used in URL)
 *   project_id_in_call_centr   — Project UUID (used in GraphQL mutation)
 *   sr_default_item_id         — Default SR product item ID (optional, for sendOrder)
 *   sr_enabled                 — '1' to enable integration; empty/absent = disabled
 */
class SalesRenderService
{
    private const BASE_URL = 'https://de.backend.salesrender.com/companies/{company}/CRM';

    private string $apiToken;
    private string $companyId;
    private string $projectId;
    private ?int $tenantId;
    private ?SrResult $lastResult = null;

    /**
     * @param  int|null  $tenantId  When given, every request reports its outcome to SrHealthService.
     */
    public function __construct(string $apiToken, string $companyId, string $projectId, ?int $tenantId = null)
    {
        $this->apiToken   = $apiToken;
        $this->companyId  = $companyId;
        $this->projectId  = $projectId;
        $this->tenantId   = $tenantId;
    }

    /** Result of the most recent request (null before the first one). */
    public function lastResult(): ?SrResult
    {
        return $this->lastResult;
    }

    // ─── Send lead to SalesRender ─────────────────────────────────────────────

    /**
     * Create a new order (lead) in SalesRender.
     * Mirrors GAS sendOrderToSalesRender().
     *
     * @param  Order      $order
     * @param  int|null   $srItemId  SalesRender product item ID (from tenant_settings.sr_default_item_id)
     * @param  int|null   $variation SalesRender variation number; pass only for products with variations
     *                               (null → variation 1). If SR rejects it, the order is re-sent with 1.
     * @return string|null  SalesRender order ID, or null on failure
     */
    public function sendOrder(Order $order, ?int $srItemId = null, ?int $variation = null): ?string
    {
        if (!$srItemId) {
            Log::debug('SalesRenderService::sendOrder skipped — no sr_default_item_id', [
                'order_id' => $order->id,
            ]);
            return null;
        }

        $goods  = $order->goods   ?? [];
        $prices = $order->prices  ?? [];
        $phone  = PhoneNormalizer::toInternationalDigits($order->phone);

        // Price in kopecks (as in GAS: price * 100)
        $price = isset($prices[0]) ? (int) round((float) $prices[0] * 100) : 0;

        // Source URL (landing page) → native SR order field `source.uri`.
        // Optional: sent only when order.source is a valid URL.
        $source = trim((string) ($order->source ?? ''));
        // "www.test.com" / "test.com/page" without scheme → add https://; labels like "site" stay as is.
        if ($source !== '' && !preg_match('~\s~', $source) && preg_match('~^(https?://|[^/\s]+\.[^/\s]+)~i', $source)) {
            $source = UrlNormalizer::normalize($source);
        }
        $sourceFields = ($source !== '' && filter_var($source, FILTER_VALIDATE_URL))
            ? '
                            source: { uri: '
                . json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' },'
            : '';

        $attempts = ($variation !== null && $variation > 1) ? [$variation, 1] : [1];

        foreach ($attempts as $attempt => $variationNumber) {
            $id = $this->addOrder($order, $srItemId, $variationNumber, $sourceFields, $phone, $price);

            if ($id !== null) {
                if ($attempt > 0) {
                    Log::warning('SalesRenderService::sendOrder: sent with variation 1 after SR rejected the requested one', [
                        'order_id'  => $order->id,
                        'requested' => $variation,
                    ]);
                }
                return $id;
            }

            // Retrying with another variation cannot fix a token / permission problem.
            if ($this->lastResult && ($this->lastResult->isAccessProblem() || $this->lastResult->isTransient())) {
                break;
            }
        }

        return null;
    }

    private function addOrder(Order $order, int $srItemId, int $variationNumber, string $sourceFields, string $phone, int $price): ?string
    {
        $mutation = sprintf(
            'mutation {
                orderMutation {
                    addOrder(
                        input: {
                            projectId: "%s",
                            statusId: 1,%s
                            orderData: {
                                humanNameFields: {
                                    field: "name",
                                    value: { lastName: "%s" }
                                },
                                phoneFields: {
                                    field: "phone",
                                    value: "%s"
                                }
                            },
                            cart: {
                                items: [
                                    {
                                        itemId: %d,
                                        variation: %d,
                                        quantity: 1,
                                        price: %d
                                    }
                                ]
                            }
                        }
                    ) { id }
                }
            }',
            addslashes($this->projectId),
            $sourceFields,
            addslashes((string) $order->full_name),
            addslashes($phone),
            $srItemId,
            $variationNumber,
            $price
        );

        $result = $this->request($mutation, 'addOrder');

        if (!$result->ok) {
            Log::warning('SalesRenderService::sendOrder: no order ID', [
                'order_id'  => $order->id,
                'variation' => $variationNumber,
                'code'      => $result->errorCode,
                'message'   => $result->message,
            ]);
            return null;
        }

        $id = $result->data['data']['orderMutation']['addOrder']['id'] ?? null;

        if (!$id) {
            Log::warning('SalesRenderService::sendOrder: no order ID', [
                'order_id'  => $order->id,
                'variation' => $variationNumber,
            ]);
            return null;
        }

        Log::info('SalesRenderService::sendOrder: created', [
            'order_id'  => $order->id,
            'sr_id'     => $id,
            'variation' => $variationNumber,
        ]);

        return (string) $id;
    }

    // ─── Fetch order from SalesRender ─────────────────────────────────────────

    /**
     * Fetch a single order from SalesRender by its ID.
     * Mirrors GAS fetchOrderFromSalesRender().
     *
     * @return array|null  Raw SR order object, or null if not found / error
     */
    public function fetchOrder(string $srOrderId): ?array
    {
        $result = $this->fetchOrderResult($srOrderId);

        return $result->ok ? $result->data['order'] : null;
    }

    /**
     * Same as fetchOrder() but keeps the reason of a failure.
     * On success $result->data['order'] holds the raw SR order.
     */
    public function fetchOrderResult(string $srOrderId): SrResult
    {
        $query = sprintf(
            'query {
                ordersFetcher(filters: { include: { ids: ["%s"] } }) {
                    orders {
                        id
                        status { id name }
                        data {
                            stringFields  { field { id name label } value }
                            booleanFields { field { id name label } value }
                            phoneFields   { field { id name label } value { raw } }
                            addressFields {
                                field { id name label }
                                value { postcode region city address_1 address_2 building apartment countryCode }
                            }
                            humanNameFields { field { id name label } value { firstName lastName } }
                        }
                        cart {
                            items {
                                id quantity
                                pricing { unitPrice }
                                sku { item { id name } variation { number property } }
                            }
                        }
                    }
                }
            }',
            addslashes($srOrderId)
        );

        $result = $this->request($query, 'ordersFetcher');

        if (!$result->ok) {
            return $result;
        }

        $orders = $result->data['data']['ordersFetcher']['orders'] ?? [];

        if (empty($orders)) {
            Log::debug('SalesRenderService::fetchOrder: not found', ['sr_id' => $srOrderId]);
            return $this->lastResult = SrResult::failure(SrResult::ORDER_NOT_FOUND, 200);
        }

        return $this->lastResult = SrResult::success(['order' => $orders[0]]);
    }

    // ─── Update order in SalesRender ──────────────────────────────────────────

    /**
     * Send changed data of an existing order (orderMutation.updateOrder).
     *
     * @param  array<string, mixed>  $fields  UpdateOrderInput parts without id:
     *                                        orderData, cart, statusId
     */
    public function updateOrder(string $srOrderId, array $fields): SrResult
    {
        $query = 'mutation ($input: UpdateOrderInput!) { orderMutation { updateOrder(input: $input) { id } } }';

        $result = $this->request($query, 'updateOrder', ['input' => array_merge(['id' => $srOrderId], $fields)]);

        if (!$result->ok) {
            return $result;
        }

        if (empty($result->data['data']['orderMutation']['updateOrder']['id'])) {
            return $this->lastResult = SrResult::failure(SrResult::SR_REJECTED, 200, 'пустой ответ updateOrder');
        }

        return $result;
    }

    /**
     * SalesRender statuses of the project: name => id.
     * Cached for 10 minutes per company.
     *
     * @return array<string, string>|SrResult  SrResult on failure
     */
    public function fetchStatuses()
    {
        $cacheKey = 'sr_statuses:' . $this->companyId;

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->request('query { statusesFetcher { statuses { id name } } }', 'ordersFetcher');
        if (!$result->ok) {
            return $result;
        }

        $map = [];
        foreach ($result->data['data']['statusesFetcher']['statuses'] ?? [] as $row) {
            $map[(string) ($row['name'] ?? '')] = (string) ($row['id'] ?? '');
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, $map, 600);

        return $map;
    }

    /**
     * Order fields of the project: list of {id, name, label}.
     */
    public function fetchOrderFields(): SrResult
    {
        return $this->request('query { orderFieldsFetcher { fields { id name label } } }', 'ordersFetcher');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function apiUrl(): string
    {
        return str_replace('{company}', $this->companyId, self::BASE_URL);
    }

    /**
     * Execute one GraphQL request and classify the outcome.
     * Reports to SrHealthService when a tenant id is known.
     *
     * @param  array<string, mixed>  $variables
     */
    public function request(string $query, string $operation, array $variables = []): SrResult
    {
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => $this->apiToken])
                ->post($this->apiUrl(), $payload);

            $result = $this->classify($response->status(), $response->json());

            if (!$result->ok) {
                Log::warning('SalesRenderService: request failed', [
                    'operation' => $operation,
                    'status'    => $response->status(),
                    'code'      => $result->errorCode,
                    'body'      => substr($response->body(), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SalesRenderService: exception', ['error' => $e->getMessage()]);
            $result = SrResult::failure(SrResult::TRANSIENT, null, 'нет ответа');
        }

        $this->lastResult = $result;

        if ($this->tenantId !== null) {
            try {
                SrHealthService::record($this->tenantId, $operation, $result);
            } catch (\Throwable $e) {
                Log::warning('SalesRenderService: health record failed', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * @param  mixed  $json  decoded response body
     */
    private function classify(int $status, $json): SrResult
    {
        $errors = is_array($json) && isset($json['errors']) && is_array($json['errors']) ? $json['errors'] : [];
        $first  = is_array($errors[0] ?? null) ? $errors[0] : [];
        $ext    = is_array($first['extensions'] ?? null) ? $first['extensions'] : [];
        $detail = (string) ($first['message'] ?? '');

        if ($status === 401) {
            return SrResult::failure(SrResult::AUTH_INVALID, $status);
        }
        if ($status === 403) {
            return SrResult::failure(SrResult::AUTH_FORBIDDEN_IP, $status);
        }
        if ($status === 404 && ($ext['code'] ?? '') === 'COMPANY_NOT_FOUND') {
            return SrResult::failure(SrResult::CONFIG_COMPANY, $status);
        }
        if ($status === 429 || $status >= 500) {
            return SrResult::failure(SrResult::TRANSIENT, $status, 'HTTP ' . $status);
        }
        if ($status < 200 || $status >= 300) {
            return SrResult::failure(SrResult::SR_REJECTED, $status, $detail !== '' ? $detail : 'HTTP ' . $status);
        }

        if ($errors !== []) {
            foreach ($errors as $err) {
                $e = is_array($err['extensions'] ?? null) ? $err['extensions'] : [];
                if (($e['code'] ?? '') === 'FIELD_RESTRICTED_BY_ROLE') {
                    $restriction = is_array($e['restriction'] ?? null) ? $e['restriction'] : null;
                    $field       = (string) ($restriction['field'] ?? '');
                    $code        = $field === 'updateOrder' ? SrResult::PERMISSION_DENIED
                        : ($field === 'addOrder' ? SrResult::PERMISSION_DENIED_CREATE : SrResult::PERMISSION_DENIED_READ);

                    return SrResult::failure($code, $status, null, $restriction);
                }
            }

            // SalesRender throttles repeated updates of one order: retry later, do not give up.
            if (stripos($detail, 'too many updates') !== false) {
                return SrResult::failure(SrResult::TRANSIENT, $status, 'лимит частоты обновлений заказа');
            }

            // Item price must stay inside the item's price range configured in SalesRender.
            if (stripos($detail, 'price is non actual') !== false) {
                return SrResult::failure(
                    SrResult::SR_REJECTED,
                    $status,
                    'цена вне допустимого диапазона товара в SalesRender — расширьте диапазон цены (min/max) у товара или верните каталожную цену'
                );
            }

            return SrResult::failure(SrResult::SR_REJECTED, $status, $detail);
        }

        return SrResult::success(is_array($json) ? $json : [], $status);
    }
}
