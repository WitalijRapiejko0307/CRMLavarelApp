<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use App\Services\OrderHandlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderHandlersTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_managers_appear_in_handlers_for_same_order(): void
    {
        [$store, $cc, $order, $managerA, $managerB] = $this->createScenario();

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Недозвон',
            'user_id'     => $managerA->id,
            'created_at'  => now()->subHour(),
        ]);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Недозвон',
            'to_status'   => 'Подтвержден',
            'user_id'     => $managerB->id,
            'created_at'  => now(),
        ]);

        $handlers = app(OrderHandlerService::class)->handlersForOrder($order->id);

        $this->assertCount(2, $handlers);
        $this->assertEqualsCanonicalizing(
            [$managerA->id, $managerB->id],
            array_column($handlers, 'user_id')
        );
        $this->assertSame('Недозвон', collect($handlers)->firstWhere('user_id', $managerA->id)['last_status']);
        $this->assertSame('Подтвержден', collect($handlers)->firstWhere('user_id', $managerB->id)['last_status']);
    }

    public function test_handlers_included_in_orders_index_for_call_center(): void
    {
        [$store, $cc, $order, $managerA] = $this->createScenario();

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Подтвержден',
            'user_id'     => $managerA->id,
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($managerA)->get('/orders');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has("orderHandlers.{$order->id}", 1)
            ->where("orderHandlers.{$order->id}.0.name", $managerA->name)
        );
    }

    /**
     * @return array{0: Tenant, 1: Tenant, 2: Order, 3: User, 4: User}
     */
    private function createScenario(): array
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

        $managerA = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Manager A',
            'email'     => 'a@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        $managerB = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Manager B',
            'email'     => 'b@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Подтвержден',
        ]);

        return [$store, $cc, $order, $managerA, $managerB];
    }
}
