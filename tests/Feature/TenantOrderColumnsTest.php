<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantOrderColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function createStoreAdmin(string $name, string $email): User
    {
        $tenant = Tenant::create([
            'name'                => $name,
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => $email,
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createCallCenterAdmin(string $email = 'cc-columns@example.com'): User
    {
        $tenant = Tenant::create([
            'name'                => 'CC Columns',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'CC Admin',
            'email'     => $email,
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function inertiaProp($response, string $key)
    {
        $html = $response->getContent();
        if (!preg_match('/data-page="([^"]*)"/', $html, $m)) {
            return null;
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return data_get($page['props'] ?? [], $key);
    }

    public function test_store_tenant_order_columns_are_isolated_and_call_center_gets_none(): void
    {
        $tenantA = $this->createStoreAdmin('Store A Columns', 'store-a-columns@example.com');
        $tenantB = $this->createStoreAdmin('Store B Columns', 'store-b-columns@example.com');
        $ccUser  = $this->createCallCenterAdmin();

        $this->actingAs($tenantA)
            ->post('/settings/order-columns', ['fields' => ['city', 'comment']])
            ->assertRedirect();

        $responseA = $this->actingAs($tenantA)->get('/orders');
        $responseA->assertOk();
        $this->assertSame(['city', 'comment'], $this->inertiaProp($responseA, 'orderListColumns'));

        $responseB = $this->actingAs($tenantB)->get('/orders');
        $responseB->assertOk();
        $this->assertSame([], $this->inertiaProp($responseB, 'orderListColumns'));

        $responseCc = $this->actingAs($ccUser)->get('/orders');
        $responseCc->assertOk();
        $this->assertSame([], $this->inertiaProp($responseCc, 'orderListColumns'));
    }

    public function test_manager_cannot_save_order_columns(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Store Manager Columns',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $manager = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Manager',
            'email'     => 'manager-columns@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        $this->actingAs($manager)
            ->post('/settings/order-columns', ['fields' => ['comment']])
            ->assertForbidden();
    }
}
