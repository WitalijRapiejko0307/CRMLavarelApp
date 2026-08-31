<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Services\SalesRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pulls order updates from SalesRender for all "Позвонить" orders
 * that have an external_id (SalesRender order ID).
 *
 * Mirrors GAS checkAndUpdateOrders() in backend/SalesRender.gs.
 *
 * SalesRender status → CRM status:
 *   "Принят"                    → "Заказать"  (operator confirmed)
 *   "Отменен" / "Отмена" /
 *   "Дубли" / "Спам"            → "Отказ(Ошибка)"
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

        $service = new SalesRenderService($apiToken, $companyId, $projectId);

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
            try {
                $srOrderId = (string) $order->external_id;
                $srOrder   = $service->fetchOrder($srOrderId);

                if (!$srOrder) {
                    Log::debug("SyncSalesRenderJob: order not found in SR", [
                        'order_id'    => $order->id,
                        'sr_order_id' => $srOrderId,
                    ]);
                    $skipped++;
                    continue;
                }

                $srStatus = $srOrder['status']['name'] ?? '';
                $isFinal  = in_array($srStatus, self::SR_FINAL_STATUSES, true);

                if (!$isFinal) {
                    if ($this->validate($order, $srOrder)) {
                        if ($this->applyNonFinalUpdates($order, $srOrder)) {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        $skipped++;
                    }
                    continue;
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
                    $skipped++;
                    continue;
                }

                try {
                    if ($srStatus === 'Принят') {
                        $this->applyConfirmedData($order, $srOrder);
                    } else {
                        $this->applyRefusal($order, $srOrder);
                    }
                    $updated++;
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
                }
            } catch (\Throwable $e) {
                Log::error("SyncSalesRenderJob: error processing order", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
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

        $srRaw           = (string) ($phoneFields[0]['value']['raw'] ?? '');
        $normalizedSr    = preg_replace('/^\+?375/', '', $srRaw);
        $normalizedOrder = preg_replace('/\D/', '', (string) $order->phone);

        return $normalizedSr === $normalizedOrder;
    }

    /**
     * Cart + comment/upsell while SR status is not final.
     *
     * @return bool  true if anything was written
     */
    private function applyNonFinalUpdates(Order $order, array $srOrder): bool
    {
        $changed = false;

        $cart = $this->extractCart($srOrder);
        if ($cart !== null && $this->isCartChanged($order, $cart)) {
            $order->update([
                'goods'      => $cart['goods'],
                'quantities' => $cart['quantities'],
                'prices'     => $cart['prices'],
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
    private function applyConfirmedData(Order $order, array $srOrder): void
    {
        $data = [];

        $humanNameFields = $srOrder['data']['humanNameFields'] ?? [];
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
        }

        $cart = $this->extractCart($srOrder);
        if ($cart !== null) {
            $data['goods']      = $cart['goods'];
            $data['quantities'] = $cart['quantities'];
            $data['prices']     = $cart['prices'];
        }

        $data['status'] = 'Заказать';

        $order->update($data);
        $this->syncNoteFields($order, $srOrder);

        Log::info("SyncSalesRenderJob: confirmed", [
            'order_id' => $order->id,
            'goods'    => $data['goods'] ?? [],
        ]);
    }

    private function applyRefusal(Order $order, array $srOrder): void
    {
        $order->update([
            'status' => 'Отказ(Ошибка)',
        ]);

        $this->syncNoteFields($order, $srOrder);

        Log::info("SyncSalesRenderJob: refused", [
            'order_id'  => $order->id,
            'sr_status' => $srOrder['status']['name'] ?? '',
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
    private function extractCart(array $srOrder): ?array
    {
        $items = $srOrder['cart']['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $goods      = [];
        $quantities = [];
        $prices     = [];

        foreach ($items as $item) {
            $goods[]      = (string) ($item['sku']['item']['name'] ?? '');
            $quantities[] = (int) ($item['quantity'] ?? 1);
            $prices[]     = (float) ($item['pricing']['unitPrice'] ?? 0);
        }

        return [
            'goods'      => $goods,
            'quantities' => $quantities,
            'prices'     => $prices,
        ];
    }

    /**
     * @param  array{goods: string[], quantities: int[], prices: float[]}  $cart
     */
    private function isCartChanged(Order $order, array $cart): bool
    {
        return $this->normalizeList($order->goods ?? []) !== $this->normalizeList($cart['goods'])
            || $this->normalizeNumericList($order->quantities ?? []) !== $this->normalizeNumericList($cart['quantities'])
            || $this->normalizeNumericList($order->prices ?? []) !== $this->normalizeNumericList($cart['prices']);
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
