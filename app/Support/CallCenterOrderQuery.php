<?php

namespace App\Support;

use App\Models\Order;
use App\Models\TenantConnection;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

class CallCenterOrderQuery
{
    public static function forTenant(int $callCenterTenantId): Builder
    {
        return Order::withoutGlobalScope(TenantScope::class)
            ->where('call_center_tenant_id', $callCenterTenantId)
            ->whereIn('tenant_id', function ($query) use ($callCenterTenantId) {
                $query->select('store_tenant_id')
                    ->from('tenant_connections')
                    ->where('call_center_tenant_id', $callCenterTenantId)
                    ->where('status', TenantConnection::STATUS_ACTIVE);
            });
    }
}
