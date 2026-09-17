<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductUpsellFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_can_create_product_with_upsell_fields(): void
    {
        $user = $this->createStoreAdmin();

        $response = $this->actingAs($user)->postJson('/products', [
            'name'         => 'Крем',
            'stock'        => 10,
            'weight'       => 100,
            'upsell_name'  => 'Крем плюс',
            'upsell_price' => 15.5,
            'upsell_text'  => 'Добавить крем плюс?',
            'cross_name'   => 'Маска',
            'cross_price'  => 8,
            'cross_text'   => 'Маска к крему',
            'manager_note' => 'Не обещать скидку',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'tenant_id'   => $user->tenant_id,
            'name'        => 'Крем',
            'upsell_name' => 'Крем плюс',
            'cross_name'  => 'Маска',
            'manager_note'=> 'Не обещать скидку',
        ]);

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', 'Крем')
            ->first();

        $this->assertEquals(15.5, (float) $product->upsell_price);
        $this->assertEquals(8, (float) $product->cross_price);
        $this->assertSame('Добавить крем плюс?', $product->upsell_text);
        $this->assertSame('Маска к крему', $product->cross_text);
    }

    public function test_store_can_update_product_upsell_fields(): void
    {
        $user = $this->createStoreAdmin();

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Крем',
            'stock'     => 5,
            'weight'    => 100,
        ]);

        $response = $this->actingAs($user)->putJson("/products/{$product->id}", [
            'upsell_name'  => 'Крем ночной',
            'upsell_price' => 20,
            'upsell_text'  => 'Ночной уход',
        ]);

        $response->assertOk();
        $product->refresh();
        $this->assertSame('Крем ночной', $product->upsell_name);
        $this->assertEquals(20, (float) $product->upsell_price);
        $this->assertSame('Ночной уход', $product->upsell_text);
    }

    public function test_call_center_cannot_post_products(): void
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
            'name'      => 'CC Admin',
            'email'     => 'cc-products-upsell@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->actingAs($user)->postJson('/products', [
            'name'   => 'Крем',
            'stock'  => 10,
            'weight' => 100,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    private function createStoreAdmin(): User
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $store->id,
            'name'      => 'Admin',
            'email'     => 'store-upsell-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }
}
