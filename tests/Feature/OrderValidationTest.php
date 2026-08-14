<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderValidationTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Validation Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'validation@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_store_requires_phone_and_goods(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name' => 'Иванов Иван',
            'status'    => 'Позвонить',
            'goods'     => [],
        ]);

        $response->assertSessionHasErrors(['phone', 'goods']);
        $this->assertSame(0, Order::count());
    }

    public function test_store_accepts_two_part_name_with_phone_and_goods(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'  => 'Иванов Иван',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'goods'      => ['Товар А'],
            'quantities' => [1],
            'prices'     => [10],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван',
            'phone'     => '375291234567',
        ]);
    }

    public function test_store_defaults_delivery_type_to_belpost(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'  => 'Иванов Иван',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'goods'      => ['Товар А'],
            'quantities' => [1],
            'prices'     => [10],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'tenant_id'     => $user->tenant_id,
            'delivery_type' => 'belpost',
        ]);
    }

    public function test_store_rejects_single_word_name(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'  => 'Иванов',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'goods'      => ['Товар А'],
        ]);

        $response->assertSessionHasErrors('full_name');
    }
}
