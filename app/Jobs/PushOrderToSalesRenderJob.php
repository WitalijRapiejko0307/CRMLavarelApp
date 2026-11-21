<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\SrPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends pending CRM edits of one order to SalesRender.
 *
 * Idempotent and not unique: it reads the order state when it runs, so several queued jobs for one
 * order collapse into a single request (the first sends, the rest find nothing pending).
 */
class PushOrderToSalesRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 120;

    private int $orderId;
    private bool $force;
    private bool $ignoreHealth;
    private ?int $userId;

    public function __construct(int $orderId, bool $force = false, bool $ignoreHealth = false, ?int $userId = null)
    {
        $this->orderId      = $orderId;
        $this->force        = $force;
        $this->ignoreHealth = $ignoreHealth;
        $this->userId       = $userId;
    }

    public function handle(SrPushService $service): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (!$order) {
            return;
        }

        app()->instance('current_tenant_id', $order->tenant_id);

        // Same lock as SyncSalesRenderJob, so the pull never interleaves with a push of one order.
        $lock = Cache::lock('sr-order:' . $order->id, 60);

        if (!$lock->get()) {
            self::dispatch($this->orderId, $this->force, $this->ignoreHealth, $this->userId)->delay(now()->addSeconds(30));

            return;
        }

        try {
            $service->push($order, $this->force, $this->ignoreHealth, $this->userId);
        } catch (\Throwable $e) {
            Log::error('PushOrderToSalesRenderJob failed', ['order_id' => $this->orderId, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
