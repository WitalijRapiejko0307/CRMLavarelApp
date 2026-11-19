<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\CustomerService;
use App\Services\OrderStockAndIncomeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /** @var OrderStockAndIncomeService */
    private $stockAndIncome;

    /** Order attributes that feed the customer profile. */
    private const PROFILE_ATTRIBUTES = [
        'status', 'full_name', 'city', 'street', 'building', 'housing', 'apartment',
        'poste_restante', 'delivery_type', 'belpost_address_id', 'europochta_store_id',
    ];

    /** @var CustomerService */
    private $customers;

    public function __construct(OrderStockAndIncomeService $stockAndIncome, CustomerService $customers)
    {
        $this->stockAndIncome = $stockAndIncome;
        $this->customers      = $customers;
    }

    /**
     * Link every new order to the store's customer base (by phone).
     */
    public function created(Order $order): void
    {
        $this->safely(function () use ($order) {
            $this->customers->linkOrder($order);
            $this->customers->syncProfileFromOrder($order);
        }, $order);
    }

    /**
     * Keep customer link and profile in sync after phone / status / address edits.
     * Never breaks the order update: the customer base is auxiliary data.
     */
    public function updated(Order $order): void
    {
        $this->safely(function () use ($order) {
            if ($order->wasChanged('phone')) {
                $oldCustomerId = $order->customer_id;
                $this->customers->linkOrder($order);

                if ($oldCustomerId && (int) $oldCustomerId !== (int) $order->customer_id) {
                    $old = \App\Models\Customer::withoutGlobalScopes()->find($oldCustomerId);
                    if ($old) {
                        $this->customers->refreshOrdersCount($old);
                    }
                }
            }

            if ($order->wasChanged(self::PROFILE_ATTRIBUTES)
                || $order->wasChanged('phone')) {
                $this->customers->syncProfileFromOrder($order);
            }
        }, $order);
    }

    private function safely(callable $callback, Order $order): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('OrderObserver: customer base update failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
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
