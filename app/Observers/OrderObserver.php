<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * Track status changes: write history and update status_changed_at.
     * Also manage product stock for Отправлено / Возврат.
     */
    public function updating(Order $order): void
    {
        if (!$order->isDirty('status')) {
            return;
        }

        $fromStatus = $order->getOriginal('status');
        $toStatus   = $order->status;

        // Record history entry
        OrderStatusHistory::create([
            'order_id'   => $order->id,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'created_at'  => Carbon::now(),
        ]);

        // Update status_changed_at
        $order->status_changed_at = Carbon::now();

        // Manage product stock
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];

        if ($toStatus === 'Отправлено') {
            $this->adjustStock($goods, $quantities, $order->tenant_id, true);
        }

        if ($toStatus === 'Возврат' && $fromStatus === 'Отправлено') {
            $this->adjustStock($goods, $quantities, $order->tenant_id, false);
        }

        // Aggregate revenue into products.sold_amount when operator marks order as completed.
        // Mirrors GAS sumOrder() logic: fires on "Завершен" for belpost/europochta orders.
        if ($toStatus === 'Завершен' && in_array($order->delivery_type, ['belpost', 'europochta'], true)) {
            $this->aggregateSoldAmount($goods, $quantities, $order->prices ?? [], $order->tenant_id);
        }
    }

    /**
     * Aggregate revenue into products.sold_amount.
     * Mirrors GAS sumOrder() — col O (prices) + col G (quantities) → goodsSheet col 20.
     */
    private function aggregateSoldAmount(array $goods, array $quantities, array $prices, int $tenantId): void
    {
        foreach ($goods as $index => $goodName) {
            $qty   = (int)($quantities[$index] ?? 1);
            $price = (float)($prices[$index] ?? 0);
            $amount = round($qty * $price, 2);

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', $goodName)
                ->first();

            if (!$product) {
                Log::warning("OrderObserver: product not found for sold_amount", ['name' => $goodName]);
                continue;
            }

            $product->sold_amount = round($product->sold_amount + $amount, 2);
            $product->save();
        }
    }

    /**
     * Adjust product stock. $decrease=true to subtract (shipped), false to add (return).
     */
    private function adjustStock(array $goods, array $quantities, int $tenantId, bool $decrease): void
    {
        foreach ($goods as $index => $goodName) {
            $qty = (int) ($quantities[$index] ?? 1);

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', $goodName)
                ->first();

            if (!$product) {
                Log::warning("OrderObserver: product not found", ['name' => $goodName]);
                continue;
            }

            $delta          = $decrease ? -$qty : $qty;
            $product->stock = max(0, $product->stock + $delta);

            if ($decrease) {
                $product->sold_count  += $qty;
            }

            $product->save();
        }
    }
}
