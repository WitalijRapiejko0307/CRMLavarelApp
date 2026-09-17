<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderRoundRobinTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $type, string $name): Tenant
    {
        return Tenant::create([
            'name'                => $name,
            'type'                => $type,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role, string $email, string $name = 'User'): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'email'     => $email,
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function connect(Tenant $store, Tenant $cc): void
    {
        TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_ACTIVE,
            'requested_at'          => now(),
            'approved_at'           => now(),
        ]);
    }

    private function enableRoundRobin(Tenant $cc): void
    {
        TenantSetting::put($cc->id, 'cc_round_robin', '1');
    }

    /**
     * @return array{0: Tenant, 1: Tenant, 2: string}
     */
    private function connectedStoreWithWebhook(): array
    {
        $store  = $this->createTenant(Tenant::TYPE_STORE, 'Store RR');
        $cc     = $this->createTenant(Tenant::TYPE_CALL_CENTER, 'CC RR');
        $secret = 'test-webhook-secret-token-value-here';
        TenantSetting::put($store->id, 'webhook_secret', $secret);
        $this->connect($store, $cc);

        return [$store, $cc, $secret];
    }

    private function postLead(string $secret, string $name, string $phone)
    {
        return $this->postJson('/api/webhook/lead', [
            'name'    => $name,
            'phone'   => $phone,
            'offer'   => 'Product',
            'options' => 10,
        ], [
            'X-Webhook-Token' => $secret,
        ]);
    }

    private function indexOrderNames($response): array
    {
        $names = [];
        $response->assertInertia(function ($page) use (&$names) {
            $names = collect($page->toArray()['props']['orders']['data'])->pluck('full_name')->all();
        });

        return $names;
    }

    private function getSettingsPage(User $user)
    {
        $headers = [
            'X-Inertia'        => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        $manifest = public_path('mix-manifest.json');
        if (is_file($manifest)) {
            $headers['X-Inertia-Version'] = md5_file($manifest);
        }

        return $this->actingAs($user)->get('/settings', $headers);
    }

    public function test_webhook_ring_assigns_two_operators_three_leads(): void
    {
        [$store, $cc, $secret] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $op1 = $this->createUser($cc, 'operator', 'op1-rr@example.com', 'Op One');
        $op2 = $this->createUser($cc, 'operator', 'op2-rr@example.com', 'Op Two');

        $this->postLead($secret, 'Лидов Один Один', '375291111111')->assertOk();
        $this->postLead($secret, 'Лидов Два Два', '375291111112')->assertOk();
        $this->postLead($secret, 'Лидов Три Три', '375291111113')->assertOk();

        $assigned = Order::withoutGlobalScopes()
            ->where('tenant_id', $store->id)
            ->orderBy('id')
            ->pluck('assigned_user_id')
            ->all();

        $this->assertSame([$op1->id, $op2->id, $op1->id], $assigned);

        $counts = array_count_values($assigned);
        $this->assertSame(2, $counts[$op1->id]);
        $this->assertSame(1, $counts[$op2->id]);
    }

    public function test_round_robin_off_leaves_orders_unassigned_and_visible_to_everyone(): void
    {
        [$store, $cc, $secret] = $this->connectedStoreWithWebhook();
        $admin = $this->createUser($cc, 'admin', 'cc-admin-off@example.com', 'CC Admin');
        $this->createUser($cc, 'operator', 'op-off@example.com', 'Op Off');

        $this->postLead($secret, 'Лидов Ааа Ааа', '375291111121')->assertOk();
        $this->postLead($secret, 'Лидов Ббб Ббб', '375291111122')->assertOk();
        $this->postLead($secret, 'Лидов Ввв Ввв', '375291111123')->assertOk();

        $this->assertSame(
            3,
            Order::withoutGlobalScopes()->where('tenant_id', $store->id)->whereNull('assigned_user_id')->count()
        );

        $response = $this->actingAs($admin)->get('/orders');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('roundRobinEnabled', false));
        $names = $this->indexOrderNames($response);
        $this->assertContains('Лидов Ааа Ааа', $names);
        $this->assertContains('Лидов Ббб Ббб', $names);
        $this->assertContains('Лидов Ввв Ввв', $names);
    }

    public function test_operator_sees_only_own_order_and_404s_on_others(): void
    {
        [$store, $cc] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $opA = $this->createUser($cc, 'operator', 'opa-rr@example.com', 'Op A');
        $opB = $this->createUser($cc, 'operator', 'opb-rr@example.com', 'Op B');

        $orderA = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $opA->id,
            'full_name'             => 'Заказ Оператора А',
            'status'                => 'Позвонить',
        ]);

        $orderB = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $opB->id,
            'full_name'             => 'Заказ Оператора Б',
            'status'                => 'Позвонить',
        ]);

        $indexB = $this->actingAs($opB)->get('/orders');
        $indexB->assertOk();
        $namesB = $this->indexOrderNames($indexB);
        $this->assertNotContains('Заказ Оператора А', $namesB);
        $this->assertContains('Заказ Оператора Б', $namesB);

        $this->actingAs($opB)->get("/orders/{$orderA->id}")->assertNotFound();
        $this->actingAs($opA)->get("/orders/{$orderA->id}")->assertOk();
        $this->actingAs($opA)->get("/orders/{$orderB->id}")->assertNotFound();
    }

    public function test_admin_sees_unassigned_orders_operator_does_not(): void
    {
        [$store, $cc] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $admin = $this->createUser($cc, 'admin', 'cc-admin-unassigned@example.com', 'CC Admin');
        $op    = $this->createUser($cc, 'operator', 'op-unassigned@example.com', 'Op');

        Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => null,
            'full_name'             => 'Ничейный Лид',
            'status'                => 'Позвонить',
        ]);

        Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $op->id,
            'full_name'             => 'Лид Оператора',
            'status'                => 'Позвонить',
        ]);

        $adminIndex = $this->actingAs($admin)->get('/orders');
        $adminIndex->assertOk();
        $adminNames = $this->indexOrderNames($adminIndex);
        $this->assertContains('Ничейный Лид', $adminNames);
        $this->assertContains('Лид Оператора', $adminNames);

        $opIndex = $this->actingAs($op)->get('/orders');
        $opIndex->assertOk();
        $opNames = $this->indexOrderNames($opIndex);
        $this->assertNotContains('Ничейный Лид', $opNames);
        $this->assertContains('Лид Оператора', $opNames);
    }

    public function test_admin_mine_filter_shows_only_own_assigned_orders(): void
    {
        [$store, $cc] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $admin = $this->createUser($cc, 'admin', 'cc-admin-mine@example.com', 'CC Admin');
        $op    = $this->createUser($cc, 'operator', 'op-mine@example.com', 'Op');

        Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $admin->id,
            'full_name'             => 'Лид Админа',
            'status'                => 'Позвонить',
        ]);

        Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $op->id,
            'full_name'             => 'Лид Чужой',
            'status'                => 'Позвонить',
        ]);

        $mine = $this->actingAs($admin)->get('/orders?assignee=mine');
        $mine->assertOk();
        $mineNames = $this->indexOrderNames($mine);
        $this->assertContains('Лид Админа', $mineNames);
        $this->assertNotContains('Лид Чужой', $mineNames);

        $all = $this->actingAs($admin)->get('/orders');
        $all->assertOk();
        $allNames = $this->indexOrderNames($all);
        $this->assertContains('Лид Админа', $allNames);
        $this->assertContains('Лид Чужой', $allNames);
    }

    public function test_fallback_to_managers_when_no_operators(): void
    {
        [$store, $cc, $secret] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $mgr1 = $this->createUser($cc, 'manager', 'mgr1-rr@example.com', 'Mgr One');
        $mgr2 = $this->createUser($cc, 'manager', 'mgr2-rr@example.com', 'Mgr Two');
        $this->createUser($cc, 'admin', 'admin-not-in-pool@example.com', 'Admin');

        $this->postLead($secret, 'Менеджер Лид Один', '375291111131')->assertOk();
        $this->postLead($secret, 'Менеджер Лид Два', '375291111132')->assertOk();

        $assigned = Order::withoutGlobalScopes()
            ->where('tenant_id', $store->id)
            ->orderBy('id')
            ->pluck('assigned_user_id')
            ->all();

        $this->assertSame([$mgr1->id, $mgr2->id], $assigned);
    }

    public function test_manual_store_create_assigns_when_round_robin_on(): void
    {
        [$store, $cc] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);
        $op = $this->createUser($cc, 'operator', 'op-manual@example.com', 'Op Manual');
        $storeAdmin = $this->createUser($store, 'admin', 'store-admin-rr@example.com', 'Store Admin');

        $response = $this->actingAs($storeAdmin)->post('/orders', [
            'full_name'  => 'Иванов Иван',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'goods'      => ['Товар А'],
            'quantities' => [1],
            'prices'     => [10],
        ]);

        $response->assertRedirect();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($cc->id, $order->call_center_tenant_id);
        $this->assertSame($op->id, $order->assigned_user_id);
    }

    public function test_cc_schema_includes_round_robin_toggle_store_does_not(): void
    {
        $cc = $this->createTenant(Tenant::TYPE_CALL_CENTER, 'CC Schema');
        $ccAdmin = $this->createUser($cc, 'admin', 'cc-schema-rr@example.com', 'CC Admin');

        $ccResponse = $this->getSettingsPage($ccAdmin);
        $ccResponse->assertOk();
        $ccSchema = $ccResponse->json('props.schema');
        $this->assertArrayHasKey('cc_round_robin', $ccSchema['cc']['keys']);
        $this->assertSame('toggle', $ccSchema['cc']['keys']['cc_round_robin'][1]);
        $this->assertSame('Распределять новые лиды', $ccSchema['cc']['keys']['cc_round_robin'][0]);

        $store = $this->createTenant(Tenant::TYPE_STORE, 'Store Schema');
        $storeAdmin = $this->createUser($store, 'admin', 'store-schema-rr@example.com', 'Store Admin');

        $storeResponse = $this->getSettingsPage($storeAdmin);
        $storeResponse->assertOk();
        $storeSchema = $storeResponse->json('props.schema');
        $this->assertArrayNotHasKey('cc', $storeSchema);
        $this->assertArrayNotHasKey('cc_round_robin', $storeSchema['shop']['keys'] ?? []);
    }

    public function test_operator_feed_does_not_include_other_operators_orders(): void
    {
        [$store, $cc] = $this->connectedStoreWithWebhook();
        $this->enableRoundRobin($cc);

        $opA = $this->createUser($cc, 'operator', 'opa-feed@example.com', 'Op A');
        $opB = $this->createUser($cc, 'operator', 'opb-feed@example.com', 'Op B');

        $orderA = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $opA->id,
            'full_name'             => 'Feed Order A',
            'status'                => 'Позвонить',
        ]);

        $orderB = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'assigned_user_id'      => $opB->id,
            'full_name'             => 'Feed Order B',
            'status'                => 'Позвонить',
        ]);

        $response = $this->actingAs($opB)->getJson('/api/orders/feed');

        $response->assertOk();
        $ids = collect($response->json('orders'))->pluck('id')->all();
        $this->assertNotContains($orderA->id, $ids);
        $this->assertContains($orderB->id, $ids);
    }
}
