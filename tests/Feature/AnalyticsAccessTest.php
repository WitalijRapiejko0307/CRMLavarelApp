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

class AnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_sees_only_own_metrics(): void
    {
        [$store, $cc, $order, $operator, $manager] = $this->createScenario();

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Подтвержден',
            'user_id'     => $operator->id,
            'created_at'  => now(),
        ]);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Спам',
            'user_id'     => $manager->id,
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($operator)->get('/analytics?tab=managers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Analytics/Index')
            ->where('canFilterTeam', false)
            ->has('rows', 1)
            ->where('rows.0.user_id', $operator->id)
            ->where('summary.confirmed', 1)
        );
    }

    public function test_admin_sees_all_team_metrics(): void
    {
        [$store, $cc, $order, $operator, $manager, $admin] = $this->createScenario(withAdmin: true);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Подтвержден',
            'user_id'     => $operator->id,
            'created_at'  => now(),
        ]);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Спам',
            'user_id'     => $manager->id,
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($admin)->get('/analytics?tab=managers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Analytics/Index')
            ->where('canFilterTeam', true)
            ->has('rows', 2)
            ->where('summary.touches', 2)
        );
    }

    public function test_store_cannot_access_analytics(): void
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

        $this->actingAs($user)->get('/analytics')->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: Tenant, 2: Order, 3: User, 4: User, 5?: User}
     */
    private function createScenario(bool $withAdmin = false): array
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

        $operator = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Operator',
            'email'     => 'op@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'operator',
        ]);

        $manager = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Manager',
            'email'     => 'mgr@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        $admin = null;
        if ($withAdmin) {
            $admin = User::create([
                'tenant_id' => $cc->id,
                'name'      => 'Admin',
                'email'     => 'admin@example.com',
                'password'  => Hash::make('password'),
                'role'      => 'admin',
            ]);
        }

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Подтвержден',
        ]);

        return $withAdmin
            ? [$store, $cc, $order, $operator, $manager, $admin]
            : [$store, $cc, $order, $operator, $manager];
    }
}
