<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Dup Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'dup@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_second_active_order_with_same_phone_is_marked_duplicate(): void
    {
        $user = $this->createUser();

        Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Первый Клиент',
            'phone'     => '375291234567',
            'status'    => 'Позвонить',
            'goods'     => ['A'],
        ]);

        $second = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Второй Клиент',
            'phone'     => '+375 29 123-45-67',
            'status'    => 'Позвонить',
            'goods'     => ['B'],
        ]);

        $service = app(OrderDuplicateService::class);
        $flags   = $service->flagsForOrder($second);

        $this->assertTrue($flags['is_phone_duplicate']);
        $this->assertNotNull($flags['duplicate_of_order_id']);
    }

    public function test_no_duplicate_when_first_order_is_closed(): void
    {
        $user = $this->createUser();

        Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Первый Клиент',
            'phone'     => '375291234567',
            'status'    => 'Отказ',
            'goods'     => ['A'],
        ]);

        $second = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Второй Клиент',
            'phone'     => '291234567',
            'status'    => 'Позвонить',
            'goods'     => ['B'],
        ]);

        $flags = app(OrderDuplicateService::class)->flagsForOrder($second);

        $this->assertFalse($flags['is_phone_duplicate']);
    }

    public function test_orders_index_includes_duplicate_flag(): void
    {
        $user = $this->createUser();

        Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Первый',
            'phone'     => '375291234567',
            'status'    => 'Позвонить',
            'goods'     => ['A'],
        ]);

        Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Второй',
            'phone'     => '375291234567',
            'status'    => 'Позвонить',
            'goods'     => ['B'],
        ]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has('orders.data', 2)
            ->where('orders.data.0.is_phone_duplicate', true)
            ->where('orders.data.1.is_phone_duplicate', false)
        );
    }
}
