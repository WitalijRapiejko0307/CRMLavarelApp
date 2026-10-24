<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors GAS sumOrder():
 *   Finds all orders with status "Завершен" and delivery belpost/europochta,
 *   then marks orders as "Посчитан" (observer keeps existing automatic income).
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
            : Tenant::whereIn('subscription_status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE])
                ->get()
                ->filter(fn (Tenant $tenant) => !$tenant->isReadOnly());

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
            $order->status = 'Посчитан';
            $order->save();
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
