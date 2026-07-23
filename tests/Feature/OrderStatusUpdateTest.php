<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveTenantUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Status Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'status@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_update_status_to_otdal_zayavku_writes_history(): void
    {
        $user = $this->createActiveTenantUser();

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ]);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status' => 'Отдал заявку',
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('Отдал заявку', $order->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Отдал заявку',
        ]);
    }

    public function test_update_status_rejects_unknown_status(): void
    {
        $user = $this->createActiveTenantUser();

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ]);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status' => 'Неизвестный',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, OrderStatusHistory::count());
    }
}
