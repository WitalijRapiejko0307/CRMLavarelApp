<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConnectionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function createUsers(): array
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

        TenantSetting::put($cc->id, 'connection_code', 'CCCODE01');

        $storeUser = User::create([
            'tenant_id' => $store->id,
            'name'      => 'Store Admin',
            'email'     => 'store@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $ccUser = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Admin',
            'email'     => 'cc@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        return [$store, $cc, $storeUser, $ccUser];
    }

    public function test_call_center_can_approve_pending_connection(): void
    {
        [$store, $cc, , $ccUser] = $this->createUsers();

        $connection = TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_PENDING,
            'requested_at'          => now(),
        ]);

        $response = $this->actingAs($ccUser)->post("/connections/{$connection->id}/approve");

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_connections', [
            'id'     => $connection->id,
            'status' => TenantConnection::STATUS_ACTIVE,
        ]);
    }

    public function test_call_center_can_reject_pending_connection(): void
    {
        [$store, $cc, , $ccUser] = $this->createUsers();

        $connection = TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_PENDING,
            'requested_at'          => now(),
        ]);

        $response = $this->actingAs($ccUser)->post("/connections/{$connection->id}/reject");

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_connections', [
            'id'     => $connection->id,
            'status' => TenantConnection::STATUS_REJECTED,
        ]);
    }
}
