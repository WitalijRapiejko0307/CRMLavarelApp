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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls order updates from SalesRender for all "Позвонить" orders
 * that have an external_id (SalesRender order ID).
 *
 * Mirrors GAS checkAndUpdateOrders() in backend/SalesRender.gs.
 *
 * SalesRender status → CRM status:
 *   "Принят"   → "Заказать"  (operator confirmed, fills address/goods/name)
 *   "Отменен"  → "Отказ(Ошибка)"
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

        // Only process orders in "Позвонить" that have an SR order ID
        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'Позвонить')
            ->whereNotNull('external_id')
            ->get();

        Log::info("SyncSalesRenderJob: checking {$orders->count()} orders", [
            'tenant_id' => $this->tenantId,
        ]);

        $updated = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            try {
                $srOrderId = (string) $order->external_id;
                $srOrder   = $service->fetchOrder($srOrderId);

                if (!$srOrder) {
                    Log::debug("SyncSalesRenderJob: order not found in SR", [
                        'order_id'   => $order->id,
                        'sr_order_id' => $srOrderId,
                    ]);
                    $skipped++;
                    continue;
                }

                $srStatus = $srOrder['status']['name'] ?? '';

                if ($srStatus !== 'Принят' && $srStatus !== 'Отменен') {
                    $skipped++;
                    continue;
                }

                // Validate: SR order ID and phone must match
                if (!$this->validate($order, $srOrder)) {
                    Log::warning("SyncSalesRenderJob: validation failed", [
                        'order_id'    => $order->id,
                        'sr_order_id' => $srOrderId,
                    ]);
                    $skipped++;
                    continue;
                }

                if ($srStatus === 'Отменен') {
                    $order->update(['status' => 'Отказ(Ошибка)']);
                    Log::info("SyncSalesRenderJob: cancelled", ['order_id' => $order->id]);
                    $updated++;
                    continue;
                }

                // 'Принят' → fill in confirmed data and set status to 'Заказать'
                $this->applyConfirmedData($order, $srOrder);
                $updated++;

            } catch (\Throwable $e) {
                Log::error("SyncSalesRenderJob: error processing order", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Log::info("SyncSalesRenderJob: done", [
            'tenant_id' => $this->tenantId,
            'updated'   => $updated,
            'skipped'   => $skipped,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Validate that the SR order ID and phone number match our record.
     * Mirrors GAS validateOrder().
     */
    private function validate(Order $order, array $srOrder): bool
    {
        // ID check
        if ((string) ($srOrder['id'] ?? '') !== (string) $order->external_id) {
            return false;
        }

        // Phone check
        $phoneFields = $srOrder['data']['phoneFields'] ?? [];
        if (empty($phoneFields)) {
            return false;
        }

        $srRaw            = (string) ($phoneFields[0]['value']['raw'] ?? '');
        $normalizedSr     = preg_replace('/^\+?375/', '', $srRaw);
        $normalizedOrder  = preg_replace('/\D/', '', (string) $order->phone);

        return $normalizedSr === $normalizedOrder;
    }

    /**
     * Apply confirmed order data from SalesRender to our Order record.
     * Mirrors GAS updateRowWithOrderData().
     */
    private function applyConfirmedData(Order $order, array $srOrder): void
    {
        $data = [];

        // Full name (firstName + lastName from humanNameFields)
        $humanNameFields = $srOrder['data']['humanNameFields'] ?? [];
        if (!empty($humanNameFields)) {
            $firstName = $humanNameFields[0]['value']['firstName'] ?? '';
            $lastName  = $humanNameFields[0]['value']['lastName']  ?? '';
            $fullName  = trim($firstName . ' ' . $lastName);
            if ($fullName) {
                $data['full_name'] = $fullName;
            }
        }

        // Address
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

        // Goods, quantities, prices from cart
        $items = $srOrder['cart']['items'] ?? [];
        if (!empty($items)) {
            $goods      = [];
            $quantities = [];
            $prices     = [];

            foreach ($items as $item) {
                $goods[]      = $item['sku']['item']['name'] ?? '';
                $quantities[] = (int) ($item['quantity'] ?? 1);
                $prices[]     = (float) (($item['pricing']['unitPrice'] ?? 0));
            }

            $data['goods']      = $goods;
            $data['quantities'] = $quantities;
            $data['prices']     = $prices;
        }

        // Comment / upsale note from stringFields / booleanFields
        $stringFields  = $srOrder['data']['stringFields']  ?? [];
        $booleanFields = $srOrder['data']['booleanFields'] ?? [];

        $comment = !empty($stringFields)  ? (string) ($stringFields[0]['value']  ?? '') : '';
        $upsale  = !empty($booleanFields) ? (string) ($booleanFields[0]['field']['label'] ?? '') : '';
        $note    = trim($comment . ' ' . $upsale);

        if ($note) {
            $data['sms_log'] = $note;
        }

        $data['status'] = 'Заказать';

        $order->update($data);

        Log::info("SyncSalesRenderJob: confirmed", [
            'order_id' => $order->id,
            'goods'    => $data['goods'] ?? [],
        ]);
    }

    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
