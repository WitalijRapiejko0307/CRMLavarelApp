<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveTenantUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Filter Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'filter@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_orders_index_filters_by_date_range(): void
    {
        $user = $this->createActiveTenantUser();

        $julyOrder = Order::create([
            'tenant_id'  => $user->tenant_id,
            'full_name'  => 'Июльский Заказ Тестов',
            'status'     => 'Позвонить',
            'created_at' => '2026-07-15 10:00:00',
        ]);

        $augustOrder = Order::create([
            'tenant_id'  => $user->tenant_id,
            'full_name'  => 'Августовский Заказ Тестов',
            'status'     => 'Позвонить',
            'created_at' => '2026-08-15 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders?date_from=2026-07-01&date_to=2026-07-31');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($julyOrder->full_name, $content);
        $this->assertStringNotContainsString($augustOrder->full_name, $content);
    }

    public function test_orders_index_without_date_filters_shows_all_orders(): void
    {
        $user = $this->createActiveTenantUser();

        $julyOrder = Order::create([
            'tenant_id'  => $user->tenant_id,
            'full_name'  => 'Июльский Заказ Тестов',
            'status'     => 'Позвонить',
            'created_at' => '2026-07-15 10:00:00',
        ]);

        $augustOrder = Order::create([
            'tenant_id'  => $user->tenant_id,
            'full_name'  => 'Августовский Заказ Тестов',
            'status'     => 'Позвонить',
            'created_at' => '2026-08-15 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($julyOrder->full_name, $content);
        $this->assertStringContainsString($augustOrder->full_name, $content);
    }
}
