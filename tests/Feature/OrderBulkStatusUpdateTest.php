<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderBulkStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function createStoreUser(string $name = 'Bulk Store'): User
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
            'email'     => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createOrder(int $tenantId, string $status = 'Позвонить'): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => $status,
        ]);
    }

    public function test_bulk_update_status_success(): void
    {
        $user = $this->createStoreUser();

        $orders = collect(range(1, 3))->map(fn () => $this->createOrder($user->tenant_id));

        $response = $this->actingAs($user)->patchJson('/orders/bulk-status', [
            'order_ids' => $orders->pluck('id')->all(),
            'status'    => 'Перезвонить',
        ]);

        $response->assertOk()
            ->assertJson(['updated' => 3, 'failed' => []]);

        foreach ($orders as $order) {
            $this->assertSame('Перезвонить', $order->fresh()->status);
        }

        $this->assertSame(3, OrderStatusHistory::count());
    }

    public function test_bulk_update_partial_forbidden(): void
    {
        $userA = $this->createStoreUser('Store A');
        $userB = $this->createStoreUser('Store B');

        $ownOrder   = $this->createOrder($userA->tenant_id);
        $foreignOrder = $this->createOrder($userB->tenant_id);

        $response = $this->actingAs($userA)->patchJson('/orders/bulk-status', [
            'order_ids' => [$ownOrder->id, $foreignOrder->id],
            'status'    => 'Перезвонить',
        ]);

        $response->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('failed.0.id', $foreignOrder->id)
            ->assertJsonPath('failed.0.reason', 'forbidden');

        $this->assertSame('Перезвонить', $ownOrder->fresh()->status);
        $this->assertSame('Позвонить', $foreignOrder->fresh()->status);
    }

    public function test_bulk_update_rejects_invalid_status(): void
    {
        $user = $this->createStoreUser();
        $order = $this->createOrder($user->tenant_id);

        $response = $this->actingAs($user)->patchJson('/orders/bulk-status', [
            'order_ids' => [$order->id],
            'status'    => 'Неизвестный',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Позвонить', $order->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_call_center_bulk_rejects_mail_status(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->patchJson('/orders/bulk-status', [
            'order_ids' => [$order->id],
            'status'    => 'Оформлен',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Позвонить', $order->fresh()->status);
    }

    public function test_call_center_bulk_updates_allowed_status(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->patchJson('/orders/bulk-status', [
            'order_ids' => [$order->id],
            'status'    => 'Подтвержден',
        ]);

        $response->assertOk()
            ->assertJson(['updated' => 1, 'failed' => []]);

        $this->assertSame('Подтвержден', $order->fresh()->status);
    }

    public function test_expired_tenant_cannot_bulk_update_status(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Expired Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'expired-bulk@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $order = $this->createOrder($tenant->id);

        $response = $this->actingAs($user)->patchJson('/orders/bulk-status', [
            'order_ids' => [$order->id],
            'status'    => 'Перезвонить',
        ]);

        $response->assertForbidden();
        $this->assertSame('Позвонить', $order->fresh()->status);
    }

    private function createAssignedOrder(): array
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

        $ccUser = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Admin',
            'email'     => 'cc-bulk@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Позвонить',
        ]);

        return [$ccUser, $order];
    }
}
