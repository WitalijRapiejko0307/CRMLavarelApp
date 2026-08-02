<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantTypeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_center_gets_403_on_belpost_routes(): void
    {
        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Admin',
            'email'     => 'cc@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->actingAs($user)->get('/belpost');

        $response->assertForbidden();
    }

    public function test_call_center_gets_403_on_products_routes(): void
    {
        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Admin',
            'email'     => 'cc-products@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->actingAs($user)->get('/products')->assertForbidden();
    }

    public function test_store_can_access_products_routes(): void
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $store->id,
            'name'      => 'Admin',
            'email'     => 'store-products@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->actingAs($user)->get('/products')->assertOk();
    }

    public function test_store_can_access_belpost_routes(): void
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $store->id,
            'name'      => 'Admin',
            'email'     => 'store@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->actingAs($user)->get('/belpost')->assertOk();
    }
}
