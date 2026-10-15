<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TenantDeletionService
{
    public function deleteTenant(Tenant $tenant, ?int $actorUserId = null): void
    {
        $tenantId = $tenant->id;

        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            return;
        }

        DB::transaction(function () use ($tenantId, $actorUserId): void {
            if (Schema::hasTable('orders')) {
                DB::table('orders')
                    ->where('call_center_tenant_id', $tenantId)
                    ->where('tenant_id', '!=', $tenantId)
                    ->update(['call_center_tenant_id' => null]);
            }

            if (Schema::hasTable('orders') && Schema::hasTable('order_status_history')) {
                DB::table('order_status_history')
                    ->whereIn('order_id', function ($query) use ($tenantId): void {
                        $query->select('id')
                            ->from('orders')
                            ->where('tenant_id', $tenantId);
                    })
                    ->delete();
            }

            $this->deleteByTenantId('income', $tenantId);
            $this->deleteByTenantId('orders', $tenantId);
            $this->deleteByTenantId('belpost_tracking_drafts', $tenantId);
            $this->deleteByTenantId('expenses', $tenantId);
            $this->deleteByTenantId('mail_batches', $tenantId);
            $this->deleteByTenantId('products', $tenantId);
            $this->deleteByTenantId('tenant_settings', $tenantId);
            $this->deleteByTenantId('users', $tenantId);

            DB::table('tenants')->where('id', $tenantId)->delete();

            Log::info('tenant.deleted', [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $actorUserId,
                'action'        => 'tenant.deleted',
            ]);
        });
    }

    private function deleteByTenantId(string $table, int $tenantId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('tenant_id', $tenantId)->delete();
    }
}
