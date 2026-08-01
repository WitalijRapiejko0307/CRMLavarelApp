<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Order $order): bool
    {
        return $this->canAccess($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->canAccess($user, $order);
    }

    public function updateStatus(User $user, Order $order): bool
    {
        return $this->canAccess($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        if (!$user->isTenantUser() || $user->role !== 'admin') {
            return false;
        }

        $tenant = $user->tenant;

        if ($tenant->isCallCenter()) {
            return false;
        }

        return $order->tenant_id === $user->tenant_id;
    }

    public function updateDeliveryType(User $user, Order $order): bool
    {
        return $this->canAccess($user, $order);
    }

    protected function canAccess(User $user, Order $order): bool
    {
        if (!$user->isTenantUser()) {
            return false;
        }

        $tenant = $user->tenant;

        if ($tenant->isStore()) {
            return $order->tenant_id === $user->tenant_id;
        }

        if ($tenant->isCallCenter()) {
            if ($order->call_center_tenant_id !== $user->tenant_id) {
                return false;
            }

            return TenantConnection::where('store_tenant_id', $order->tenant_id)
                ->where('call_center_tenant_id', $user->tenant_id)
                ->where('status', TenantConnection::STATUS_ACTIVE)
                ->exists();
        }

        return false;
    }
}
