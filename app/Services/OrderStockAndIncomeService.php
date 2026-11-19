<?php

namespace App\Services;

use App\Models\Income;
use App\Models\Order;
use App\Models\OrderStockMovement;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderStockAndIncomeService
{
    private const MONEY_STATUSES = ['Завершен'];

    /** Goods may already have left the warehouse (not money-received). */
    private const GOODS_LEFT_STATUSES = [
        'Отправлено',
        'В отделении',
        'Возврат в пути',
        'Забрать деньги',
        'Передан курьеру',
    ];

    private const RETURN_RESTORE_FROM = ['Отправлено', 'В отделении', 'Возврат в пути'];

    public function handleStatusChange(Order $order, ?string $fromStatus, string $toStatus, Carbon $eventAt): void
    {
        if ($this->shouldRestoreStock($order, $fromStatus, $toStatus)) {
            $this->applyRestore($order, "{$fromStatus} → {$toStatus}");
        }

        if ($this->shouldAttemptDeduction($order, $fromStatus, $toStatus)) {
            $this->applyDeduction($order, "{$fromStatus} → {$toStatus}");
        }

        $this->handleIncome($order, $fromStatus, $toStatus, $eventAt);
    }

    private function handleIncome(Order $order, ?string $fromStatus, string $toStatus, Carbon $eventAt): void
    {
        if ($this->isMoneyStatus($toStatus) && !$this->isMoneyStatus($fromStatus)) {
            $this->createAutomaticIncome($order, $eventAt);
        }

        if ($this->isMoneyStatus($fromStatus) && !$this->isMoneyStatus($toStatus)) {
            $this->deleteAutomaticIncome($order);
        }

        if (
            $toStatus === 'Возврат'
            && in_array($fromStatus, self::RETURN_RESTORE_FROM, true)
        ) {
            $this->deleteAutomaticIncome($order);
        }
    }

    private function shouldAttemptDeduction(Order $order, ?string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === 'Возврат' && in_array($toStatus, ['Отправлено', 'Передан курьеру'], true)) {
            return true;
        }

        $delivery = $order->delivery_type ?? 'belpost';

        if (in_array($delivery, ['belpost', 'europochta'], true)) {
            if ($toStatus === 'Отправлено') {
                return true;
            }
            if (in_array($toStatus, self::MONEY_STATUSES, true) && !$this->orderFullyDeducted($order)) {
                return true;
            }
        }

        if (in_array($delivery, ['personal', 'pickup'], true) && in_array($toStatus, self::MONEY_STATUSES, true)) {
            return true;
        }

        if ($delivery === 'courier' && $toStatus === 'Передан курьеру') {
            return true;
        }

        return false;
    }

    private function shouldRestoreStock(Order $order, ?string $fromStatus, string $toStatus): bool
    {
        if ($toStatus === 'Возврат' && in_array($fromStatus, self::RETURN_RESTORE_FROM, true)) {
            return true;
        }

        $delivery = $order->delivery_type ?? 'belpost';

        if ($delivery === 'courier' && $fromStatus === 'Передан курьеру' && $this->isPreShipment($toStatus)) {
            return true;
        }

        if ($this->isPreShipment($toStatus) && $this->isGoodsLeftStatus($fromStatus)) {
            return true;
        }

        if (
            in_array($delivery, ['personal', 'pickup'], true)
            && $this->isMoneyStatus($fromStatus)
            && !$this->isMoneyStatus($toStatus)
        ) {
            return true;
        }

        if (
            in_array($delivery, ['belpost', 'europochta'], true)
            && $this->isMoneyStatus($fromStatus)
            && $this->isPreShipment($toStatus)
        ) {
            return true;
        }

        return false;
    }

    private function applyDeduction(Order $order, string $reason): void
    {
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];
        $net        = $this->netDeducted($order);

        foreach ($goods as $index => $goodName) {
            $qty = (int) ($quantities[$index] ?? 1);

            $product = $this->findProduct($order, $goodName);

            if (!$product) {
                Log::warning('OrderStockAndIncomeService: product not found for deduction', ['name' => $goodName]);
                continue;
            }

            $variation = $this->variations()->resolveForStock($product, $order->variationAt((int) $index));
            $key       = $this->key($product->id, $variation?->id);

            $currentNet = $net[$key] ?? 0;
            if ($currentNet >= $qty) {
                continue;
            }

            $deductQty = $qty - $currentNet;

            DB::transaction(function () use ($order, $product, $variation, $deductQty, $reason) {
                $this->recordMovement($order, $product->id, $variation?->id, $deductQty, OrderStockMovement::DIRECTION_OUT, $reason);
                $this->changeStock($product, $variation, -$deductQty);
            });

            $net[$key] = $currentNet + $deductQty;
        }
    }

    private function applyRestore(Order $order, string $reason): void
    {
        foreach ($this->netDeducted($order) as $key => $currentNet) {
            if ($currentNet <= 0) {
                continue;
            }

            [$productId, $variationId] = array_map('intval', explode(':', $key));

            $product = Product::withoutGlobalScopes()->find($productId);
            if (!$product) {
                continue;
            }

            $variation = $variationId
                ? ProductVariation::withoutGlobalScopes()->find($variationId)
                : null;

            DB::transaction(function () use ($order, $product, $variation, $currentNet, $reason) {
                $this->recordMovement($order, $product->id, $variation?->id, $currentNet, OrderStockMovement::DIRECTION_IN, $reason);
                $this->changeStock($product, $variation, $currentNet);
            });
        }
    }

    /**
     * @return array<string, int> "productId:variationId" (0 = no variation) => net qty out
     */
    private function netDeducted(Order $order): array
    {
        $rows = OrderStockMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->get();

        $baseVariationIds = [];
        $net = [];
        foreach ($rows as $row) {
            $variationId = $row->variation_id;

            // Movement made while the product had no variations: it belongs to variation 1 now.
            if (!$variationId) {
                if (!array_key_exists($row->product_id, $baseVariationIds)) {
                    $baseVariationIds[$row->product_id] = ProductVariation::withoutGlobalScopes()
                        ->where('product_id', $row->product_id)
                        ->where('number', 1)
                        ->value('id');
                }
                $variationId = $baseVariationIds[$row->product_id];
            }

            $key   = $this->key($row->product_id, $variationId);
            $delta = $row->direction === OrderStockMovement::DIRECTION_OUT ? $row->qty : -$row->qty;
            $net[$key] = ($net[$key] ?? 0) + $delta;
        }

        return $net;
    }

    private function orderFullyDeducted(Order $order): bool
    {
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];
        $net        = $this->netDeducted($order);

        foreach ($goods as $index => $goodName) {
            $qty = (int) ($quantities[$index] ?? 1);

            $product = $this->findProduct($order, $goodName);

            if (!$product) {
                continue;
            }

            $variation = $this->variations()->resolveForStock($product, $order->variationAt((int) $index));

            if (($net[$this->key($product->id, $variation?->id)] ?? 0) < $qty) {
                return false;
            }
        }

        return $goods !== [];
    }

    private function recordMovement(Order $order, int $productId, ?int $variationId, int $qty, string $direction, string $reason): void
    {
        OrderStockMovement::withoutGlobalScopes()->create([
            'tenant_id'    => $order->tenant_id,
            'order_id'     => $order->id,
            'product_id'   => $productId,
            'variation_id' => $variationId,
            'qty'          => $qty,
            'direction'    => $direction,
            'reason'       => $reason,
        ]);
    }

    /**
     * Change stock of a variation (and refresh the product total) or of a plain product.
     */
    private function changeStock(Product $product, ?ProductVariation $variation, int $delta): void
    {
        if ($variation) {
            $variation = ProductVariation::withoutGlobalScopes()->lockForUpdate()->find($variation->id) ?? $variation;
            $variation->stock = max(0, $variation->stock + $delta);
            $variation->save();
            $this->variations()->recalcProductTotals($product);

            return;
        }

        $product->stock = max(0, $product->stock + $delta);
        $product->save();
    }

    private function findProduct(Order $order, $goodName): ?Product
    {
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('name', $goodName)
            ->first();
    }

    private function key(int $productId, ?int $variationId): string
    {
        return $productId . ':' . (int) $variationId;
    }

    private function variations(): ProductVariationService
    {
        return app(ProductVariationService::class);
    }

    private function createAutomaticIncome(Order $order, Carbon $eventAt): void
    {
        if (Income::withoutGlobalScopes()->where('order_id', $order->id)->exists()) {
            return;
        }

        Income::withoutGlobalScopes()->create([
            'tenant_id'   => $order->tenant_id,
            'order_id'    => $order->id,
            'amount'      => $this->orderTotal($order),
            'source'      => 'заказ',
            'description' => $order->id . ' ' . $order->full_name,
            'date'        => $eventAt->toDateString(),
        ]);

        $this->adjustProductSales($order, 1);
    }

    private function deleteAutomaticIncome(Order $order): void
    {
        $deleted = Income::withoutGlobalScopes()->where('order_id', $order->id)->delete();
        if ($deleted > 0) {
            $this->adjustProductSales($order, -1);
        }
    }

    /**
     * Sold count and revenue follow automatic income: once on first money status, reversed when that row is removed.
     */
    private function adjustProductSales(Order $order, int $sign): void
    {
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];
        $prices     = $order->prices ?? [];

        foreach ($goods as $index => $goodName) {
            if ($goodName === null || $goodName === '') {
                continue;
            }

            $qty   = (int) ($quantities[$index] ?? 1);
            $price = (float) ($prices[$index] ?? 0);

            $product = $this->findProduct($order, $goodName);

            if (!$product) {
                Log::warning('OrderStockAndIncomeService: product not found for sold stats', ['name' => $goodName]);
                continue;
            }

            $variation = $this->variations()->resolveForStock($product, $order->variationAt((int) $index));

            if ($variation) {
                $variation->sold_count  = max(0, $variation->sold_count + ($sign * $qty));
                $variation->sold_amount = max(0, round($variation->sold_amount + ($sign * $qty * $price), 2));
                $variation->save();
                $this->variations()->recalcProductTotals($product);
                continue;
            }

            $product->sold_count  = max(0, $product->sold_count + ($sign * $qty));
            $product->sold_amount = max(0, round($product->sold_amount + ($sign * $qty * $price), 2));
            $product->save();
        }
    }

    private function orderTotal(Order $order): float
    {
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];
        $prices     = $order->prices ?? [];
        $total      = 0.0;

        foreach ($goods as $index => $goodName) {
            if ($goodName === null || $goodName === '') {
                continue;
            }
            $total += (int) ($quantities[$index] ?? 1) * (float) ($prices[$index] ?? 0);
        }

        return round($total, 2);
    }

    private function isMoneyStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::MONEY_STATUSES, true);
    }

    private function isGoodsLeftStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::GOODS_LEFT_STATUSES, true);
    }

    private function isPreShipment(string $status): bool
    {
        return in_array($status, Order::preShipmentStatuses(), true);
    }
}
