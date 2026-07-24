<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderDestroyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createActiveTenantUser(string $role = 'admin'): User
    {
        $tenant = Tenant::create([
            'name'                => 'Destroy Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'User',
            'email'     => "destroy-{$role}@example.com",
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function createExpiredTenantAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Expired Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'expired-destroy@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_admin_deletes_deletable_order(): void
    {
        $user = $this->createActiveTenantUser('admin');

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Отказ',
        ]);

        $response = $this->actingAs($user)->delete("/orders/{$order->id}");

        $response->assertRedirect('/orders');
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_admin_deletes_deletable_order_via_json(): void
    {
        $user = $this->createActiveTenantUser('admin');

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Отказ',
        ]);

        $response = $this->actingAs($user)->deleteJson("/orders/{$order->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_operator_cannot_delete_order(): void
    {
        $user = $this->createActiveTenantUser('operator');

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Отказ',
        ]);

        $response = $this->actingAs($user)->delete("/orders/{$order->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_admin_cannot_delete_non_deletable_status(): void
    {
        $user = $this->createActiveTenantUser('admin');

        foreach (['Посчитан', 'Позвонить'] as $status) {
            $order = Order::create([
                'tenant_id' => $user->tenant_id,
                'full_name' => 'Иванов Иван Иванович',
                'status'    => $status,
            ]);

            $response = $this->actingAs($user)->delete("/orders/{$order->id}");

            $response->assertStatus(422);
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
        }
    }

    public function test_read_only_tenant_cannot_delete_order(): void
    {
        $user = $this->createExpiredTenantAdmin();

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Отказ',
        ]);

        $response = $this->actingAs($user)->delete("/orders/{$order->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
