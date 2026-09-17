<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Collection;

class OrderAssignmentService
{
    public function __construct(
        protected ConnectionService $connectionService
    ) {}

    public function assignCallCenter(Order $order): void
    {
        if (!$order->call_center_tenant_id) {
            $connection = $this->connectionService->activeConnectionForStore($order->tenant_id);

            if ($connection) {
                $order->call_center_tenant_id = $connection->call_center_tenant_id;
                $order->saveQuietly();
            }
        }

        $this->assignRoundRobinOperator($order);
    }

    public function storeHasInternalCallCenter(int $storeTenantId): bool
    {
        return TenantConnection::where('store_tenant_id', $storeTenantId)
            ->where('status', TenantConnection::STATUS_ACTIVE)
            ->exists();
    }

    public function backfillActiveOrders(TenantConnection $connection): int
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $connection->store_tenant_id)
            ->whereNull('call_center_tenant_id')
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->update(['call_center_tenant_id' => $connection->call_center_tenant_id]);
    }

    protected function assignRoundRobinOperator(Order $order): void
    {
        $ccTenantId = $order->call_center_tenant_id;

        if (!$ccTenantId || $order->assigned_user_id) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $ccTenantId)
            ->where('key', 'cc_round_robin')
            ->first();

        if (!$setting || $setting->value !== '1') {
            return;
        }

        $pool = $this->roundRobinPool((int) $ccTenantId);

        if ($pool->isEmpty()) {
            return;
        }

        $cursor = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $ccTenantId)
            ->where('key', 'cc_round_robin_last_user_id')
            ->first();

        $last = (int) ($cursor?->value ?? 0);
        $user = $pool->first(fn (User $candidate) => $candidate->id > $last) ?? $pool->first();

        $order->assigned_user_id = $user->id;
        $order->saveQuietly();

        TenantSetting::put((int) $ccTenantId, 'cc_round_robin_last_user_id', (string) $user->id);
    }

    /**
     * Operators first (by id); managers if the CC has no operators. Never admin.
     *
     * @return Collection<int, User>
     */
    protected function roundRobinPool(int $ccTenantId): Collection
    {
        $operators = User::query()
            ->where('tenant_id', $ccTenantId)
            ->where('role', 'operator')
            ->orderBy('id')
            ->get();

        if ($operators->isNotEmpty()) {
            return $operators;
        }

        return User::query()
            ->where('tenant_id', $ccTenantId)
            ->where('role', 'manager')
            ->orderBy('id')
            ->get();
    }
}
