<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductPageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_can_save_product_page_url(): void
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

        $response = $this->actingAs($user)->postJson('/products', [
            'name'     => 'Крем',
            'page_url' => 'https://shop.example/krem',
            'stock'    => 10,
            'weight'   => 100,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'tenant_id' => $store->id,
            'name'      => 'Крем',
            'page_url'  => 'https://shop.example/krem',
        ]);
    }

    public function test_call_center_show_resolves_product_link_from_catalog(): void
    {
        [$ccUser, $order] = $this->createAssignedOrderWithProduct(
            'Крем',
            'https://shop.example/krem'
        );

        $response = $this->actingAs($ccUser)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Show')
            ->where('productLinks.Крем', 'https://shop.example/krem')
        );
    }

    private function createAssignedOrderWithProduct(string $productName, ?string $pageUrl): array
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

        Product::withoutGlobalScopes()->create([
            'tenant_id' => $store->id,
            'name'      => $productName,
            'page_url'  => $pageUrl,
            'stock'     => 5,
            'weight'    => 100,
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
            'goods'                 => [$productName],
            'quantities'            => [1],
            'prices'                => [50],
        ]);

        return [$ccUser, $order];
    }
}
