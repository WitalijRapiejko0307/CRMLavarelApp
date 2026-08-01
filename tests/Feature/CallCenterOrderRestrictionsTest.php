<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CallCenterOrderRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_center_cannot_update_track_number(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->put("/orders/{$order->id}", [
            'track_number' => 'BY123456789BY',
        ]);

        $response->assertRedirect();
        $this->assertNull($order->fresh()->track_number);
    }

    public function test_call_center_cannot_set_mail_status(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->from("/orders/{$order->id}")->patch("/orders/{$order->id}/status", [
            'status' => 'Оформлен',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('Позвонить', $order->fresh()->status);
    }

    private function createAssignedOrder(): array
    {
        $store = Tenant::create([
            'name'                => 'Store',
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

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Позвонить',
        ]);

        return [$ccUser, $order];
    }
}
