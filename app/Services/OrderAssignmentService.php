<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantConnection;

class OrderAssignmentService
{
    public function __construct(
        protected ConnectionService $connectionService
    ) {}

    public function assignCallCenter(Order $order): void
    {
        if ($order->call_center_tenant_id) {
            return;
        }

        $connection = $this->connectionService->activeConnectionForStore($order->tenant_id);

        if (!$connection) {
            return;
        }

        $order->call_center_tenant_id = $connection->call_center_tenant_id;
        $order->saveQuietly();
    }

    public function storeHasInternalCallCenter(int $storeTenantId): bool
    {
        return TenantConnection::where('store_tenant_id', $storeTenantId)
            ->where('status', TenantConnection::STATUS_ACTIVE)
            ->exists();
    }
}
