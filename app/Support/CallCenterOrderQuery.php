<?php

namespace App\Support;

use App\Models\Order;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
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

    public static function applyAssigneeVisibility(Builder $query, User $user): Builder
    {
        $tenant = $user->tenant;

        if (!$tenant || !$tenant->isCallCenter()) {
            return $query;
        }

        if (!static::roundRobinEnabled((int) $tenant->id)) {
            return $query;
        }

        if ($user->role === 'operator') {
            $query->where('assigned_user_id', $user->id);
        }

        return $query;
    }

    public static function applyMineFilter(Builder $query, User $user): Builder
    {
        return $query->where('assigned_user_id', $user->id);
    }

    public static function roundRobinEnabled(int $ccTenantId): bool
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $ccTenantId)
            ->where('key', 'cc_round_robin')
            ->first();

        return $setting && $setting->value === '1';
    }
}
