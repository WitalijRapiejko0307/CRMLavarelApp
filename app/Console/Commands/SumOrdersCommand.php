<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors GAS sumOrder():
 *   Finds all orders with status "Завершен" and delivery belpost/europochta,
 *   aggregates sold_count and sold_amount into products,
 *   then marks orders as "Посчитан".
 *
 * Usage:
 *   php artisan crm:sum-orders           (all tenants)
 *   php artisan crm:sum-orders --tenant=1 (specific tenant)
 */
class SumOrdersCommand extends Command
{
    protected $signature = 'crm:sum-orders {--tenant= : Process only this tenant ID}';

    protected $description = 'Aggregate revenue from completed orders (Завершен → Посчитан)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->processTenant($tenant->id, $tenant->name);
        }

        return self::SUCCESS;
    }

    private function processTenant(int $tenantId, string $tenantName): void
    {
        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'Завершен')
            ->whereIn('delivery_type', ['belpost', 'europochta'])
            ->get();

        if ($orders->isEmpty()) {
            $this->line("[{$tenantName}] No orders to process.");
            return;
        }

        $processed = 0;

        foreach ($orders as $order) {
            $goods      = $order->goods      ?? [];
            $quantities = $order->quantities ?? [];
            $prices     = $order->prices     ?? [];

            if (!$goods) {
                $order->status = 'Посчитан';
                $order->saveQuietly();
                continue;
            }

            foreach ($goods as $i => $goodName) {
                $qty    = (int)($quantities[$i] ?? 1);
                $price  = (float)($prices[$i] ?? 0);
                $amount = round($qty * $price, 2);

                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('name', $goodName)
                    ->first();

                if (!$product) {
                    $this->warn("[{$tenantName}] Product not found: {$goodName} (order #{$order->id})");
                    Log::warning('SumOrdersCommand: product not found', [
                        'tenant_id' => $tenantId,
                        'order_id'  => $order->id,
                        'product'   => $goodName,
                    ]);
                    continue;
                }

                // Increment sold_count and sold_amount
                $product->sold_count  += $qty;
                $product->sold_amount  = round($product->sold_amount + $amount, 2);
                $product->save();
            }

            // Mark order as counted (saveQuietly to avoid re-triggering observer)
            $order->status = 'Посчитан';
            $order->saveQuietly();

            $processed++;
        }

        $this->info("[{$tenantName}] Processed {$processed} / {$orders->count()} orders.");

        Log::info('SumOrdersCommand completed', [
            'tenant_id' => $tenantId,
            'processed' => $processed,
            'total'     => $orders->count(),
        ]);
    }
}
