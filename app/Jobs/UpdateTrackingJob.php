<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Services\BelpostTrackingService;
use App\Services\EvropostService;
use App\Services\SmsService;
use App\Services\TrackingRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls Belpost and Europochta tracking APIs for all active orders of one tenant
 * and advances order statuses accordingly.
 *
 * Mirrors GAS getStatus() + loadBelpostMap() + updateBelpostStatus() + updateEvropostStatus().
 */
class UpdateTrackingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    private int $tenantId;
    private string $source;

    public function __construct(int $tenantId, string $source = 'auto')
    {
        $this->tenantId = $tenantId;
        $this->source   = $source;
    }

    public function handle(TrackingRunService $service): void
    {
        $this->setTenantContext($this->tenantId);

        Log::info("UpdateTrackingJob: start tenant {$this->tenantId}", ['source' => $this->source]);

        $jobFailed = false;

        try {
            $smsService = $this->buildSmsService();
            $this->processBelpost($service, $smsService);
            $this->processEvropost($service, $smsService);
        } catch (\Throwable $e) {
            $jobFailed = true;
            Log::error("UpdateTrackingJob: fatal error", [
                'tenant_id' => $this->tenantId,
                'error'     => $e->getMessage(),
            ]);
        } finally {
            $progress = $service->getProgress($this->tenantId) ?? [];
            $stats    = [
                'total'   => $progress['total'] ?? 0,
                'checked' => $progress['checked'] ?? 0,
                'errors'  => $progress['errors'] ?? 0,
            ];

            $status = $jobFailed ? 'failed' : 'done';
            $service->finishRun($this->tenantId, $this->source, $status, $stats);

            if ($this->source === 'auto' && !$jobFailed) {
                $service->saveAutoStats(
                    $this->tenantId,
                    $stats['total'],
                    $stats['checked'],
                    $stats['errors']
                );
            }

            Log::info("UpdateTrackingJob: done tenant {$this->tenantId}", [
                'source'  => $this->source,
                'status'  => $status,
                'checked' => $stats['checked'],
                'total'   => $stats['total'],
            ]);
        }
    }

    private function processBelpost(TrackingRunService $service, ?SmsService $sms): void
    {
        $orders = $this->belpostOrders();

        if ($orders->isEmpty()) {
            return;
        }

        $authToken = TenantSetting::get('auth_token_bp', '');

        if (!$authToken) {
            Log::debug("UpdateTrackingJob: no auth_token_bp for tenant {$this->tenantId}");
            foreach ($orders as $order) {
                $service->incrementProgress($this->tenantId);
            }
            return;
        }

        $trackingService = new BelpostTrackingService($authToken);
        $map             = $trackingService->loadMap();

        Log::info("UpdateTrackingJob: Belpost orders to check", [
            'count'     => $orders->count(),
            'tenant_id' => $this->tenantId,
        ]);

        foreach ($orders as $order) {
            $hadError = false;

            try {
                $trackNumber = trim((string) $order->track_number);

                if (array_key_exists($trackNumber, $map)) {
                    $info = $map[$trackNumber];
                } else {
                    Log::debug("UpdateTrackingJob: Belpost direct search", ['track' => $trackNumber]);
                    $info = $trackingService->directSearch($trackNumber);
                }

                if ($info === null) {
                    Log::warning("UpdateTrackingJob: track not found", [
                        'track'    => $trackNumber,
                        'order_id' => $order->id,
                    ]);
                } else {
                    $this->applyBelpostStatus($order, $info['event'], $info['createdAt'], $sms);
                }
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error("UpdateTrackingJob: Belpost order error", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            } finally {
                $service->incrementProgress($this->tenantId, $hadError);
            }
        }
    }

    private function belpostOrders()
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('delivery_type', 'belpost')
            ->whereNotNull('track_number')
            ->whereIn('status', ['Оформлен', 'Передан на почту', 'Отправлено', 'В отделении'])
            ->get();
    }

    private function applyBelpostStatus(
        Order $order,
        ?string $event,
        ?string $eventAt,
        ?SmsService $sms
    ): void {
        $currentStatus = $order->status;

        if ($currentStatus === 'Оформлен' && $event) {
            $this->changeStatus($order, 'Отправлено', $eventAt);
            if ($sms) {
                $sms->sendForOrder($order, 0);
            }
            return;
        }

        if ($event === 'Поступило в учреждение доставки' && $currentStatus !== 'В отделении') {
            $this->changeStatus($order, 'В отделении', $eventAt);
            if ($sms) {
                $sms->sendForOrder($order, 1);
            }
            return;
        }

        if ($event === 'Вручено' && $currentStatus !== 'Забрать деньги') {
            $this->changeStatus($order, 'Забрать деньги', $eventAt);
            return;
        }

        if ($event === 'Вручено отправителю' && $currentStatus !== 'Возврат') {
            $this->changeStatus($order, 'Возврат', $eventAt);
            return;
        }

        if ($currentStatus === 'В отделении' && $sms) {
            $sms->sendForOrder($order, 2);
        }
    }

    private function processEvropost(TrackingRunService $service, ?SmsService $sms): void
    {
        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('delivery_type', 'europochta')
            ->whereNotNull('track_number')
            ->whereIn('status', ['Оформлен', 'Передан на почту', 'Отправлено', 'В отделении'])
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        Log::info("UpdateTrackingJob: Europochta orders to check", [
            'count'     => $orders->count(),
            'tenant_id' => $this->tenantId,
        ]);

        $trackingService = new EvropostService();

        foreach ($orders as $order) {
            $hadError = false;

            try {
                $trackNumber = trim((string) $order->track_number);
                $events      = $trackingService->getTracking($trackNumber);
                $this->applyEvropostStatus($order, $events, $sms);
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error("UpdateTrackingJob: Europochta order error", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            } finally {
                $service->incrementProgress($this->tenantId, $hadError);
            }

            usleep(100000);
        }
    }

    private function applyEvropostStatus(
        Order $order,
        ?array $events,
        ?SmsService $sms
    ): void {
        if (empty($events) || empty($events[0]['timeX'])) {
            Log::debug("UpdateTrackingJob: no Europochta events", ['order_id' => $order->id]);
            return;
        }

        $latest     = $events[0];
        $clientInfo = (string) ($latest['client_info'] ?? '');
        $eventAt    = (string) ($latest['timeX'] ?? null);

        $currentStatus = $order->status;

        if (
            $currentStatus !== 'Отправлено'
            && $clientInfo !== 'Заявка на почтовое отправление зарегистрирована'
        ) {
            $this->changeStatus($order, 'Отправлено', $eventAt);
            if ($sms) {
                $sms->sendForOrder($order, 0);
            }
            return;
        }

        if (
            $currentStatus !== 'В отделении'
            && (
                str_contains($clientInfo, 'прибыло для выдачи')
                || str_contains($clientInfo, 'прибыло на ОПС выдачи')
                || str_contains($clientInfo, 'Срок бесплатного хранения почтового отправления')
                || (str_contains($clientInfo, 'отправление прибыло') && str_contains($clientInfo, 'для возврата'))
            )
        ) {
            $this->changeStatus($order, 'В отделении', $eventAt);
            if ($sms) {
                $sms->sendForOrder($order, 1);
            }
            return;
        }

        if (
            $currentStatus !== 'Возврат'
            && str_contains($clientInfo, 'Почтовое отправление возвращено отправителю')
        ) {
            $this->changeStatus($order, 'Возврат', $eventAt);
            return;
        }

        if (
            $currentStatus !== 'Забрать деньги'
            && str_contains($clientInfo, 'Почтовое отправление выдано')
        ) {
            $this->changeStatus($order, 'Забрать деньги', $eventAt);
            return;
        }

        if ($currentStatus === 'В отделении' && $sms) {
            $sms->sendForOrder($order, 2);
        }
    }

    private function changeStatus(Order $order, string $newStatus, ?string $eventAt): void
    {
        $order->update([
            'status'            => $newStatus,
            'status_changed_at' => $eventAt ?? now()->toDateTimeString(),
        ]);

        Log::info("UpdateTrackingJob: status changed", [
            'order_id'   => $order->id,
            'new_status' => $newStatus,
            'track'      => $order->track_number,
        ]);
    }

    private function buildSmsService(): ?SmsService
    {
        $token       = TenantSetting::get('token_sms_by', '');
        $alphanameId = TenantSetting::get('alphaname_id', '');
        $rules       = TenantSetting::get('sms_rules', '');

        if (!$token || !$alphanameId || !$rules) {
            return null;
        }

        return new SmsService($token, $alphanameId, $rules);
    }

    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
