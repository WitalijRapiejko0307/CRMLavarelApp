<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\OrderStockAndIncomeService;
use Illuminate\Support\Carbon;

class OrderObserver
{
    /** @var OrderStockAndIncomeService */
    private $stockAndIncome;

    public function __construct(OrderStockAndIncomeService $stockAndIncome)
    {
        $this->stockAndIncome = $stockAndIncome;
    }

    /**
     * Track status changes: write history and update status_changed_at.
     * Stock and finance income are driven by order_stock_movements and income.order_id.
     */
    public function updating(Order $order): void
    {
        if (!$order->isDirty('status')) {
            return;
        }

        $fromStatus = $order->getOriginal('status');
        $toStatus   = $order->status;

        $hasEventAt = $order->isDirty('status_changed_at') && $order->status_changed_at !== null;
        $eventAt    = $hasEventAt ? $order->status_changed_at : Carbon::now();

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'user_id'     => auth()->id(),
            'created_at'  => $eventAt,
        ]);

        if (!$hasEventAt) {
            $order->status_changed_at = $eventAt;
        }

        $this->stockAndIncome->handleStatusChange($order, $fromStatus, $toStatus, $eventAt);
    }
}
