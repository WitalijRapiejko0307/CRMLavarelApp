<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\CustomerService;
use App\Services\ProductVariationService;
use App\Services\SalesRenderService;
use App\Services\SrPushService;
use App\Support\PhoneNormalizer;
use App\Support\SrStatusMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls order updates from SalesRender for all "Позвонить" orders
 * that have an external_id (SalesRender order ID).
 *
 * Mirrors GAS checkAndUpdateOrders() in backend/SalesRender.gs.
 *
 * SalesRender status → CRM status (see SrStatusMap):
 *   "Принят"            → "Заказать"  (operator confirmed)
 *   "Отменен" / "Отмена" → "Отказ(Ошибка)"
 *   "Дубли"             → "Дубль"
 *   "Спам"              → "Спам"
 *
 * Fields with an unsent CRM edit (orders.sr_pending_changes) are never overwritten here.
 *
 * While SR status is not final: sync cart + comment/upsell if they changed.
 *
 * Required tenant_settings:
 *   sr_enabled                 — '1' to enable; empty/absent = skip job
 *   api_token_call_centr       — SR Bearer token
 *   company_id_in_call_centre  — SR company ID (URL)
 *   project_id_in_call_centr   — SR project UUID (GraphQL)
 */
class SyncSalesRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 180;

    private const SR_FINAL_STATUSES = ['Принят', 'Отменен', 'Отмена', 'Дубли', 'Спам'];

    private int $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    // ─── Handle ───────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $this->setTenantContext($this->tenantId);

        $srEnabled = TenantSetting::get('sr_enabled', '') === '1';
        $apiToken  = TenantSetting::get('api_token_call_centr', '');
        $companyId = TenantSetting::get('company_id_in_call_centre', '');
        $projectId = TenantSetting::get('project_id_in_call_centr', '');

        if (!$srEnabled || !$apiToken || !$companyId) {
            Log::debug("SyncSalesRenderJob: SalesRender disabled or not configured for tenant {$this->tenantId}");
            return;
        }

        $service = new SalesRenderService($apiToken, $companyId, $projectId, $this->tenantId);
        $push    = app(SrPushService::class);

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'Позвонить')
            ->whereNotNull('external_id')
            ->get();

        Log::info("SyncSalesRenderJob: checking {$orders->count()} orders", [
            'tenant_id' => $this->tenantId,
        ]);

        $updated  = 0;
        $skipped  = 0;
        $failures = [];

        foreach ($orders as $order) {
            // Same lock as PushOrderToSalesRenderJob: never interleave pull and push of one order.
            $lock = Cache::lock('sr-order:' . $order->id, 60);

            if (!$lock->get()) {
                $skipped++;
                continue;
            }

            try {
                $order->refresh();
                $outcome = $this->processOrder($service, $push, $order, $failures);
                $outcome === 'updated' ? $updated++ : $skipped++;
            } catch (\Throwable $e) {
                Log::error("SyncSalesRenderJob: error processing order", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        $this->storeFailures($failures);

        Log::info("SyncSalesRenderJob: done", [
            'tenant_id' => $this->tenantId,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'failures'  => count($failures),
        ]);
    }

    // ─── Per order ────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $failures
     * @return string  'updated' | 'skipped'
     */
    private function processOrder(SalesRenderService $service, SrPushService $push, Order $order, array &$failures): string
    {
        $srOrderId = (string) $order->external_id;
        $fetch     = $service->fetchOrderResult($srOrderId);

        if (!$fetch->ok) {
            // Permission / token problems are recorded in SrHealthService (banner), not lost as "not found".
            Log::debug("SyncSalesRenderJob: SR order not available", [
                'order_id'    => $order->id,
                'sr_order_id' => $srOrderId,
                'code'        => $fetch->errorCode,
            ]);

            return 'skipped';
        }

        $srOrder = $fetch->data['order'];

        // Unsent CRM edits stay as they are; SalesRender values equal to them are acknowledged.
        $keep   = $push->reconcile($order, $srOrder);
        $order->refresh();

        $srStatus = $srOrder['status']['name'] ?? '';
        $isFinal  = in_array($srStatus, self::SR_FINAL_STATUSES, true);

        if (!$isFinal) {
            return $this->validate($order, $srOrder) && $this->applyNonFinalUpdates($order, $srOrder, $keep)
                ? 'updated'
                : 'skipped';
        }

        if (!$this->validate($order, $srOrder)) {
            Log::warning("SyncSalesRenderJob: validation failed", [
                'order_id'    => $order->id,
                'sr_order_id' => $srOrderId,
            ]);
            $failures[] = [
                'order_id'    => $order->id,
                'external_id' => $srOrderId,
                'sr_status'   => $srStatus,
                'reason'      => 'VALIDATION',
            ];

            return 'skipped';
        }

        try {
            if ($srStatus === 'Принят') {
                $this->applyConfirmedData($order, $srOrder, $keep);
            } else {
                $this->applyRefusal($order, $srOrder);
            }

            return 'updated';
        } catch (\Throwable $e) {
            Log::error("SyncSalesRenderJob: update error", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            $failures[] = [
                'order_id'    => $order->id,
                'external_id' => $srOrderId,
                'sr_status'   => $srStatus,
                'reason'      => 'UPDATE_ERROR',
                'message'     => $e->getMessage(),
            ];

            return 'skipped';
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Validate that the SR order ID and phone number match our record.
     * Mirrors GAS validateOrder().
     */
    private function validate(Order $order, array $srOrder): bool
    {
        if ((string) ($srOrder['id'] ?? '') !== (string) $order->external_id) {
            return false;
        }

        $phoneFields = $srOrder['data']['phoneFields'] ?? [];
        if (empty($phoneFields)) {
            return false;
        }

        // Compare the last 9 digits: the order phone may be stored as 375XXXXXXXXX
        // (webhook / forms normalize it) or as a bare 9-digit number (older data).
        $srRaw = (string) ($phoneFields[0]['value']['raw'] ?? '');
        $srKey = PhoneNormalizer::lastNineDigits($srRaw);

        if ($srKey === '') {
            return false;
        }

        if ($srKey === PhoneNormalizer::lastNineDigits((string) $order->phone)) {
            return true;
        }

        // The phone was edited in the CRM and not delivered yet: SalesRender still holds the old
        // (or already the new) number — both identify the same order.
        $pendingPhone = ($order->sr_pending_changes ?: [])['phone'] ?? null;
        if ($pendingPhone) {
            foreach (['from', 'to'] as $side) {
                if ($srKey === PhoneNormalizer::lastNineDigits((string) ($pendingPhone[$side] ?? ''))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cart + comment/upsell while SR status is not final.
     *
     * @return bool  true if anything was written
     */
    private function applyNonFinalUpdates(Order $order, array $srOrder, array $keep = []): bool
    {
        $changed = false;

        $cart = in_array('cart', $keep, true) ? null : $this->extractCart($order, $srOrder);
        if ($cart !== null && $this->isCartChanged($order, $cart)) {
            $order->update([
                'goods'      => $cart['goods'],
                'quantities' => $cart['quantities'],
                'prices'     => $cart['prices'],
                'variations' => $cart['variations'],
            ]);
            $changed = true;
        }

        if ($this->syncNoteFields($order, $srOrder)) {
            $changed = true;
        }

        if ($changed) {
            Log::info("SyncSalesRenderJob: non-final update", [
                'order_id' => $order->id,
            ]);
        }

        return $changed;
    }

    /**
     * Apply confirmed order data from SalesRender to our Order record.
     * Mirrors GAS updateRowWithOrderData() for status "Принят".
     */
    private function applyConfirmedData(Order $order, array $srOrder, array $keep = []): void
    {
        $data = [];

        $humanNameFields = in_array('full_name', $keep, true) ? [] : ($srOrder['data']['humanNameFields'] ?? []);
        if (!empty($humanNameFields)) {
            $firstName = $humanNameFields[0]['value']['firstName'] ?? '';
            $lastName  = $humanNameFields[0]['value']['lastName']  ?? '';
            $fullName  = trim($firstName . ' ' . $lastName);
            if ($fullName) {
                $data['full_name'] = $fullName;
            }
        }

        $addressFields = $srOrder['data']['addressFields'] ?? [];
        if (!empty($addressFields)) {
            $addr   = $addressFields[0]['value'] ?? [];
            $region = $addr['region'] ?? '';
            $city   = $addr['city']   ?? '';
            $zip    = $addr['postcode'] ?? '';

            $data['city']      = trim("{$region} {$city} {$zip}");
            $data['street']    = trim((string) ($addr['address_1'] ?? ''));
            $data['building']  = (string) ($addr['building']  ?? '');
            $data['apartment'] = (string) ($addr['apartment'] ?? '');

            // SR has no housing / poste restante: drop the ones auto-filled from the
            // customer base so the confirmed address is not a mix of two addresses.
            $prefilled = $order->customer_prefill['fields'] ?? [];
            if (in_array('housing', $prefilled, true)) {
                $data['housing'] = null;
            }
            if (in_array('poste_restante', $prefilled, true)) {
                $data['poste_restante'] = false;
            }
        }

        $cart = in_array('cart', $keep, true) ? null : $this->extractCart($order, $srOrder);
        if ($cart !== null) {
            $data['goods']      = $cart['goods'];
            $data['quantities'] = $cart['quantities'];
            $data['prices']     = $cart['prices'];
            $data['variations'] = $cart['variations'];
        }

        if (!in_array('status', $keep, true)) {
            $data['status'] = SrStatusMap::toCrm('Принят') ?? 'Заказать';
        }

        $order->update($data);
        $this->syncNoteFields($order, $srOrder);

        // SalesRender data wins over the auto-fill from the customer base.
        try {
            $overwritten = array_values(array_intersect(
                array_keys($data),
                ['full_name', 'city', 'street', 'building', 'apartment']
            ));
            if ($overwritten !== []) {
                if (isset($data['city'])) {
                    $overwritten = array_merge($overwritten, ['housing', 'poste_restante']);
                }
                app(CustomerService::class)->markOverwritten($order, $overwritten);
            }
        } catch (\Throwable $e) {
            Log::warning("SyncSalesRenderJob: prefill marker update skipped", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        Log::info("SyncSalesRenderJob: confirmed", [
            'order_id' => $order->id,
            'goods'    => $data['goods'] ?? [],
        ]);
    }

    private function applyRefusal(Order $order, array $srOrder): void
    {
        $srStatus = (string) ($srOrder['status']['name'] ?? '');
        $status   = SrStatusMap::toCrm($srStatus) ?? 'Отказ(Ошибка)';

        $attrs = ['status' => $status];
        if ($status === 'Дубль') {
            $attrs['funnel_exclude'] = true; // same effect as marking a duplicate by hand
        }

        $order->update($attrs);

        $this->syncNoteFields($order, $srOrder);

        Log::info("SyncSalesRenderJob: refused", [
            'order_id'   => $order->id,
            'sr_status'  => $srStatus,
            'crm_status' => $status,
        ]);
    }

    /**
     * Persist comment/upsell from SR. Isolated so a missing column does not block cart/status.
     */
    private function syncNoteFields(Order $order, array $srOrder): bool
    {
        $fields = $this->extractSrNoteFields($srOrder);
        if (!$this->isNoteChanged($order, $fields)) {
            return false;
        }

        try {
            $order->update([
                'comment' => $fields['comment'],
                'upsell'  => $fields['upsell'],
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning("SyncSalesRenderJob: note update skipped", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array{goods: string[], quantities: int[], prices: float[]}|null
     */
    private function extractCart(Order $order, array $srOrder): ?array
    {
        $items = $srOrder['cart']['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $goods      = [];
        $quantities = [];
        $prices     = [];
        $variations = [];

        foreach ($items as $item) {
            $name         = (string) ($item['sku']['item']['name'] ?? '');
            $goods[]      = $name;
            $quantities[] = (int) ($item['quantity'] ?? 1);
            $prices[]     = (float) ($item['pricing']['unitPrice'] ?? 0);
            $variations[] = $this->resolveVariation($order, $name, $item['sku']['variation'] ?? null);
        }

        return [
            'goods'      => $goods,
            'quantities' => $quantities,
            'prices'     => $prices,
            'variations' => $this->normalizeVariations($variations),
        ];
    }

    /**
     * @param  array{goods: string[], quantities: int[], prices: float[]}  $cart
     */
    private function isCartChanged(Order $order, array $cart): bool
    {
        return $this->normalizeList($order->goods ?? []) !== $this->normalizeList($cart['goods'])
            || $this->normalizeNumericList($order->quantities ?? []) !== $this->normalizeNumericList($cart['quantities'])
            || $this->normalizeNumericList($order->prices ?? []) !== $this->normalizeNumericList($cart['prices'])
            || json_encode($this->normalizeVariations($order->variationsAligned())) !== json_encode($cart['variations']);
    }

    /**
     * Map SR sku.variation to a catalog variation snapshot {number, name}, or null ("no variation").
     * Unknown numbers other than 1 create a variation (named from SR `property`).
     *
     * @param  array{number?: mixed, property?: mixed}|null  $srVariation
     * @return array{number: int, name: ?string}|null
     */
    private function resolveVariation(Order $order, string $goodName, ?array $srVariation): ?array
    {
        $number = isset($srVariation['number']) ? (int) $srVariation['number'] : null;
        if ($goodName === '' || !$number) {
            return null;
        }

        try {
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->where('name', $goodName)
                ->first();
            if (!$product) {
                return null;
            }

            $variation = app(ProductVariationService::class)
                ->resolve($product, $number, isset($srVariation['property']) ? (string) $srVariation['property'] : null);
        } catch (\Throwable $e) {
            Log::warning('SyncSalesRenderJob: variation resolve failed', [
                'order_id' => $order->id,
                'good'     => $goodName,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        return $variation ? ['number' => $variation->number, 'name' => $variation->name] : null;
    }

    /**
     * null when no position has a variation, otherwise the list as is.
     *
     * @param  array<int, array|null>|null  $variations
     */
    private function normalizeVariations(?array $variations): ?array
    {
        if (!$variations) {
            return null;
        }

        foreach ($variations as $v) {
            if ($v !== null) {
                return array_values(array_map(
                    static fn ($v) => $v === null ? null : ['number' => (int) $v['number'], 'name' => $v['name'] ?? null],
                    $variations
                ));
            }
        }

        return null;
    }

    /**
     * @return array{comment: ?string, upsell: ?string}
     */
    private function extractSrNoteFields(array $srOrder): array
    {
        $stringFields  = $srOrder['data']['stringFields']  ?? [];
        $booleanFields = $srOrder['data']['booleanFields'] ?? [];

        $comment = !empty($stringFields) ? trim((string) ($stringFields[0]['value'] ?? '')) : '';
        $upsale  = !empty($booleanFields) ? trim((string) ($booleanFields[0]['field']['label'] ?? '')) : '';

        return [
            'comment' => $comment !== '' ? $comment : null,
            'upsell'  => $upsale !== '' ? $upsale : null,
        ];
    }

    /**
     * @param  array{comment: ?string, upsell: ?string}  $fields
     */
    private function isNoteChanged(Order $order, array $fields): bool
    {
        return $this->normalizeNote($order->comment) !== $this->normalizeNote($fields['comment'])
            || $this->normalizeNote($order->upsell) !== $this->normalizeNote($fields['upsell']);
    }

    private function normalizeNote(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function normalizeList(array $list): array
    {
        return array_map(static function ($v) {
            return trim((string) $v);
        }, array_values($list));
    }

    private function normalizeNumericList(array $list): array
    {
        return array_map(static function ($v) {
            return (string) (0 + $v);
        }, array_values($list));
    }

    /**
     * @param  array<int, array<string, mixed>>  $failures
     */
    private function storeFailures(array $failures): void
    {
        TenantSetting::put(
            $this->tenantId,
            'sr_last_sync_failures',
            json_encode(array_values($failures), JSON_UNESCAPED_UNICODE)
        );
        TenantSetting::put($this->tenantId, 'sr_last_sync_at', now()->toIso8601String());
    }

    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
