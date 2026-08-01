<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CallCenterOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function setupConnectedTenants(): array
    {
        $store = Tenant::create([
            'name'                => 'Store A',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $otherCc = Tenant::create([
            'name'                => 'Other CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_ACTIVE,
            'requested_at'          => now(),
            'approved_at'           => now(),
        ]);

        $ccUser = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Admin',
            'email'     => 'cc@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $assignedOrder = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Позвонить',
        ]);

        $foreignOrder = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $otherCc->id,
            'full_name'             => 'Петров Петр Петрович',
            'status'                => 'Позвонить',
        ]);

        return [$ccUser, $assignedOrder, $foreignOrder];
    }

    public function test_call_center_sees_assigned_orders_in_index(): void
    {
        [$ccUser, $assignedOrder] = $this->setupConnectedTenants();

        $response = $this->actingAs($ccUser)->get('/orders');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($assignedOrder->full_name, $content);
    }

    public function test_call_center_cannot_view_foreign_order(): void
    {
        [$ccUser, , $foreignOrder] = $this->setupConnectedTenants();

        $response = $this->actingAs($ccUser)->get("/orders/{$foreignOrder->id}");

        $response->assertNotFound();
    }

    public function test_call_center_can_update_assigned_order_status(): void
    {
        [$ccUser, $assignedOrder] = $this->setupConnectedTenants();

        $response = $this->actingAs($ccUser)->patch("/orders/{$assignedOrder->id}/status", [
            'status' => 'Заказать',
        ]);

        $response->assertRedirect();
        $this->assertSame('Заказать', $assignedOrder->fresh()->status);
    }
}
