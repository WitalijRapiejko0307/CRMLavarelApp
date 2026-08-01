<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConnectionRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Store Admin',
            'email'     => 'store@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createCallCenterWithCode(string $code = 'TESTCODE'): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'Call Center',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        TenantSetting::put($tenant->id, 'connection_code', $code);
        TenantSetting::put($tenant->id, 'shop_name', 'Call Center');

        return $tenant;
    }

    public function test_store_can_request_connection_by_code(): void
    {
        $store = $this->createStoreAdmin();
        $this->createCallCenterWithCode('ABCD1234');

        $response = $this->actingAs($store)->post('/connections', [
            'code' => 'ABCD1234',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_connections', [
            'store_tenant_id'       => $store->tenant_id,
            'call_center_tenant_id' => Tenant::where('type', Tenant::TYPE_CALL_CENTER)->first()->id,
            'status'                => TenantConnection::STATUS_PENDING,
        ]);
    }

    public function test_duplicate_pending_request_returns_error(): void
    {
        $store = $this->createStoreAdmin();
        $cc    = $this->createCallCenterWithCode('ABCD1234');

        TenantConnection::create([
            'store_tenant_id'       => $store->tenant_id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_PENDING,
            'requested_at'          => now(),
        ]);

        $response = $this->actingAs($store)->from('/settings')->post('/connections', [
            'code' => 'ABCD1234',
        ]);

        $response->assertSessionHasErrors('code');
    }
}
