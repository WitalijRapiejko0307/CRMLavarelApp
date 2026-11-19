<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PhoneNormalizer;
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

    public function __construct(string $apiToken, string $companyId, string $projectId)
    {
        $this->apiToken   = $apiToken;
        $this->companyId  = $companyId;
        $this->projectId  = $projectId;
    }

    // ─── Send lead to SalesRender ─────────────────────────────────────────────

    /**
     * Create a new order (lead) in SalesRender.
     * Mirrors GAS sendOrderToSalesRender().
     *
     * @param  Order      $order
     * @param  int|null   $srItemId  SalesRender product item ID (from tenant_settings.sr_default_item_id)
     * @return string|null  SalesRender order ID, or null on failure
     */
    public function sendOrder(Order $order, ?int $srItemId = null): ?string
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

        // Comment goes to SR as a string field (same as GAS). json_encode gives a
        // valid quoted GraphQL string, incl. escaped quotes and newlines.
        $comment       = trim((string) ($order->comment ?? ''));
        $commentFields = $comment === ''
            ? ''
            : ',
                                stringFields: [{ field: "comment", value: '
                . json_encode($comment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' }]';

        // Source URL (landing page) → native SR order field `source.uri`.
        // Optional like the comment: sent only when order.source is a valid URL.
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
                                }%s
                            },
                            cart: {
                                items: [
                                    {
                                        itemId: %d,
                                        variation: 1,
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
            $commentFields,
            $srItemId,
            $price
        );

        $response = $this->post(['query' => $mutation]);

        if (!$response) {
            return null;
        }

        $id = $response['data']['orderMutation']['addOrder']['id'] ?? null;

        if (!$id) {
            $errors = $response['errors'] ?? [];
            Log::warning('SalesRenderService::sendOrder: no order ID', [
                'order_id' => $order->id,
                'errors'   => $errors,
            ]);
            return null;
        }

        Log::info('SalesRenderService::sendOrder: created', [
            'order_id' => $order->id,
            'sr_id'    => $id,
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
                                sku { item { id name } }
                            }
                        }
                    }
                }
            }',
            addslashes($srOrderId)
        );

        $response = $this->post(['query' => $query]);

        if (!$response) {
            return null;
        }

        $orders = $response['data']['ordersFetcher']['orders'] ?? [];

        if (empty($orders)) {
            Log::debug('SalesRenderService::fetchOrder: not found', ['sr_id' => $srOrderId]);
            return null;
        }

        return $orders[0];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function apiUrl(): string
    {
        return str_replace('{company}', $this->companyId, self::BASE_URL);
    }

    /**
     * @return array|null  Decoded JSON response, or null on error
     */
    private function post(array $payload): ?array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => $this->apiToken])
                ->post($this->apiUrl(), $payload);

            if (!$response->successful()) {
                Log::warning('SalesRenderService: HTTP error', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            return $response->json();

        } catch (\Throwable $e) {
            Log::error('SalesRenderService: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
