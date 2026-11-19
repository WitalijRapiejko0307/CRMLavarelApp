<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\CustomerService;
use Illuminate\Console\Command;

/**
 * Builds the customer base from existing orders. Idempotent: safe to run again
 * (re-links orders, rebuilds profiles from the last successful order).
 *
 *   php artisan customers:backfill            # all store tenants
 *   php artisan customers:backfill --tenant=5 # a single store
 */
class BackfillCustomers extends Command
{
    protected $signature = 'customers:backfill {--tenant= : Only this tenant id}';

    protected $description = 'Create customers from existing orders (by phone) and fill profiles from the last successful order';

    public function handle(CustomerService $customers): int
    {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::query()->where('type', Tenant::TYPE_STORE)->pluck('id')->all();

        foreach ($tenantIds as $tenantId) {
            $linked = 0;

            Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($orders) use ($customers, &$linked) {
                    foreach ($orders as $order) {
                        // Ascending id: the newest successful order is applied last and wins.
                        $customers->linkOrder($order);
                        $customers->syncProfileFromOrder($order);
                        $linked++;
                    }
                });

            Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->chunkById(500, function ($rows) use ($customers) {
                    foreach ($rows as $customer) {
                        $customers->refreshOrdersCount($customer);
                    }
                });

            $this->info("tenant {$tenantId}: {$linked} orders processed");
        }

        return self::SUCCESS;
    }
}
