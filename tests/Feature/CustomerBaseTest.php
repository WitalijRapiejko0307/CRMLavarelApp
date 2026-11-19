<?php

namespace Tests\Feature;

use App\Jobs\SyncSalesRenderJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\Assert;
use Tests\TestCase;

class CustomerBaseTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name = 'Shop'): Tenant
    {
        return Tenant::create([
            'name'                => $name,
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
    }

    private function admin(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'admin' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function order(Tenant $tenant, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Иванов Иван',
            'status'     => 'Позвонить',
            'phone'      => '375291234567',
            'goods'      => ['Товар А'],
            'quantities' => [1],
            'prices'     => [10],
        ], $overrides));
    }

    /** A delivered order that feeds the customer profile. */
    private function deliveredOrder(Tenant $tenant, array $overrides = []): Order
    {
        return $this->order($tenant, array_merge([
            'full_name'           => 'Петров Пётр Петрович',
            'status'              => 'Завершен',
            'city'                => 'Минск',
            'street'              => 'Ленина',
            'building'            => '5',
            'apartment'           => '12',
            'delivery_type'       => 'europochta',
            'europochta_store_id' => 321,
            'goods'               => ['Старый товар'],
        ], $overrides));
    }

    private function lead(string $secret, array $extra = [])
    {
        return $this->postJson('/api/webhook/lead', array_merge([
            'name'    => 'Иван',
            'phone'   => '+375 29 123-45-67',
            'offer'   => 'Новый товар',
            'options' => 25,
        ], $extra), ['X-Webhook-Token' => $secret]);
    }

    private function webhookStore(): array
    {
        $tenant = $this->store();
        TenantSetting::put($tenant->id, 'webhook_secret', 'secret-' . $tenant->id);

        return [$tenant, 'secret-' . $tenant->id];
    }

    // ─── Customer base ───────────────────────────────────────────────────────

    public function test_orders_with_same_phone_share_one_customer_in_any_format(): void
    {
        $tenant = $this->store();
        $a = $this->order($tenant, ['phone' => '375291234567']);
        $b = $this->order($tenant, ['phone' => '291234567']);
        $c = $this->order($tenant, ['phone' => '80291234567']);

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
        $this->assertSame($a->fresh()->customer_id, $b->fresh()->customer_id);
        $this->assertSame($a->fresh()->customer_id, $c->fresh()->customer_id);
        $this->assertSame(3, Customer::withoutGlobalScopes()->first()->orders_count);
    }

    public function test_profile_is_built_only_from_successful_orders(): void
    {
        $tenant = $this->store();
        $this->deliveredOrder($tenant);
        $this->order($tenant, [
            'status' => 'Спам', 'full_name' => 'Спамер Спам', 'city' => 'Брест', 'street' => 'Мусорная',
            'delivery_type' => 'courier',
        ]);
        $this->order($tenant, ['status' => 'Отказ', 'city' => 'Гомель', 'street' => 'Отказная']);

        $customer = Customer::withoutGlobalScopes()->first();
        $this->assertSame('Петров Пётр Петрович', $customer->full_name);
        $this->assertSame('Минск', $customer->city);
        $this->assertSame('europochta', $customer->delivery_type);
    }

    public function test_older_order_does_not_override_newer_profile(): void
    {
        $tenant = $this->store();
        $old = $this->order($tenant, ['status' => 'Подтвержден', 'city' => 'Старый город', 'street' => 'Старая']);
        $this->deliveredOrder($tenant, ['city' => 'Новый город']);

        $old->update(['status' => 'Завершен']);

        $this->assertSame('Новый город', Customer::withoutGlobalScopes()->first()->city);
    }

    public function test_blacklist_mark_is_not_copied_to_profile(): void
    {
        $tenant = $this->store();
        $this->deliveredOrder($tenant, ['full_name' => 'Петров Пётр НЕБЛАГОНАДЕЖНЫЙ КЛИЕНТ']);

        $this->assertSame('Петров Пётр', Customer::withoutGlobalScopes()->first()->full_name);
    }

    public function test_customers_are_isolated_between_tenants(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        $this->deliveredOrder($a);
        $this->order($b);

        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
        $this->assertNull(app(CustomerService::class)->find($b->id, '291234567')->last_order_id);
    }

    // ─── Webhook auto-fill ───────────────────────────────────────────────────

    public function test_webhook_fills_name_address_and_delivery_but_never_goods(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $previous = $this->deliveredOrder($tenant);

        $this->lead($secret)->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $this->assertNotSame($previous->id, $order->id);
        $this->assertSame('Петров Пётр Петрович', $order->full_name);
        $this->assertSame('Минск', $order->city);
        $this->assertSame('Ленина', $order->street);
        $this->assertSame('europochta', $order->delivery_type);
        $this->assertSame(321, (int) $order->europochta_store_id);

        // The new product is untouched.
        $this->assertSame(['Новый товар'], $order->goods);
        $this->assertEquals([25.0], $order->prices);
        $this->assertSame('Позвонить', $order->status);

        $this->assertSame($previous->id, $order->customer_prefill['source_order_id']);
        $this->assertContains('city', $order->customer_prefill['fields']);
        $this->assertNull($order->customer_prefill_ack_at);
        $this->assertNotNull($order->customer_lookup_at);
    }

    public function test_webhook_keeps_full_lead_name_and_blacklist_mark(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $this->deliveredOrder($tenant);

        $this->lead($secret, ['name' => 'Сидоров Сидор'])->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $this->assertSame('Сидоров Сидор', $order->full_name);
        $this->assertNotContains('full_name', $order->customer_prefill['fields']);
        $this->assertSame('Минск', $order->city);
    }

    public function test_webhook_for_unknown_phone_fills_nothing(): void
    {
        [$tenant, $secret] = $this->webhookStore();

        $this->lead($secret)->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('Иван', $order->full_name);
        $this->assertNull($order->city);
        $this->assertNull($order->customer_prefill);
        $this->assertNotNull($order->customer_lookup_at);
    }

    public function test_webhook_does_not_use_problem_orders_for_prefill(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $this->order($tenant, ['status' => 'Возврат', 'city' => 'Брест', 'street' => 'Возвратная']);

        $this->lead($secret)->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $this->assertNull($order->city);
        $this->assertNull($order->customer_prefill);
    }

    public function test_second_lookup_is_not_performed_for_the_same_phone(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $this->deliveredOrder($tenant);
        $this->lead($secret)->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $lookupAt = $order->customer_lookup_at;

        // Operator clears the address; the profile also changes meanwhile.
        $order->forceFill(['city' => null, 'street' => null, 'building' => null, 'apartment' => null])->saveQuietly();
        Customer::withoutGlobalScopes()->first()->forceFill(['city' => 'Другой'])->saveQuietly();

        $filled = app(CustomerService::class)->applyPrefill($order->fresh());

        $this->assertSame([], $filled);
        $this->assertNull($order->fresh()->city);
        $this->assertEquals($lookupAt, $order->fresh()->customer_lookup_at);
    }

    // ─── Order page, lookup API ──────────────────────────────────────────────

    public function test_show_page_exposes_prefill_notice_and_problem_history(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $user = $this->admin($tenant);
        $this->deliveredOrder($tenant);
        $return = $this->order($tenant, ['status' => 'Возврат']);
        $spam   = $this->order($tenant, ['status' => 'Спам']);
        $this->lead($secret)->assertOk();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();

        $this->actingAs($user)->get("/orders/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customerPrefill.acknowledged', false)
                ->has('customerPrefill.fields')
                ->where('customerInfo.found', true)
                ->has('customerInfo.problems', 2)
                ->where('customerInfo.problems.0.status', 'Спам')
                ->where('customerInfo.problems.1.status', 'Возврат'));
    }

    public function test_saving_the_order_acknowledges_notice_and_does_not_search_again(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $user = $this->admin($tenant);
        $this->deliveredOrder($tenant);
        $this->lead($secret)->assertOk();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();

        $this->actingAs($user)->put("/orders/{$order->id}", ['apartment' => '99'])->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->customer_prefill_ack_at);
        $this->assertSame('99', $order->apartment);
        $this->assertSame('291234567', $order->customer_lookup_key);

        $this->actingAs($user)->get("/orders/{$order->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('customerPrefill.acknowledged', true)
                ->where('customerLookupKey', '291234567'));
    }

    public function test_ack_endpoint_marks_notice_as_seen(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $user = $this->admin($tenant);
        $this->deliveredOrder($tenant);
        $this->lead($secret)->assertOk();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();

        $this->actingAs($user)->post("/orders/{$order->id}/customer-prefill/ack")->assertNoContent();

        $this->assertNotNull($order->fresh()->customer_prefill_ack_at);
    }

    public function test_changing_phone_resets_notice_and_relinks_customer(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        $user = $this->admin($tenant);
        $this->deliveredOrder($tenant);
        $this->lead($secret)->assertOk();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $oldCustomer = $order->customer_id;

        $this->actingAs($user)->put("/orders/{$order->id}", ['phone' => '337654321'])->assertRedirect();

        $order->refresh();
        $this->assertNotSame($oldCustomer, $order->customer_id);
        $this->assertNull($order->customer_prefill);
        $this->assertSame('337654321', $order->customer_lookup_key);
    }

    public function test_lookup_api_returns_profile_and_isolates_tenants(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        $this->deliveredOrder($a);
        $this->order($a, ['status' => 'Отказ(Ошибка)']);

        $this->actingAs($this->admin($a))
            ->getJson('/api/customers/lookup?phone=%2B375291234567')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('has_profile', true)
            ->assertJsonPath('customer.city', 'Минск')
            ->assertJsonPath('problems.0.status', 'Отказ(Ошибка)')
            ->assertJsonPath('last_order.goods.0', 'Старый товар');

        $this->actingAs($this->admin($b))
            ->getJson('/api/customers/lookup?phone=291234567')
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    // ─── Customers page ──────────────────────────────────────────────────────

    public function test_customers_page_lists_customers_with_problem_counts(): void
    {
        $tenant = $this->store();
        $this->deliveredOrder($tenant);
        $this->order($tenant, ['status' => 'Спам']);
        $this->order($tenant, ['phone' => '337777777']);

        $user = $this->admin($tenant);

        $this->actingAs($user)->get('/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 2));

        $this->actingAs($user)->get('/customers?problem=1')
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.problems.Спам', 1));

        $this->actingAs($user)->get('/customers?q=Петров')
            ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
    }

    public function test_customer_page_of_another_tenant_is_not_found(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        $this->deliveredOrder($a);
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $a->id)->first();

        $this->actingAs($this->admin($b))->get("/customers/{$customer->id}")->assertNotFound();
        $this->actingAs($this->admin($a))->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Show')->has('orders', 1));
    }

    // ─── SalesRender ─────────────────────────────────────────────────────────

    public function test_sales_render_confirmation_overrides_prefill_and_keeps_customer_base_working(): void
    {
        [$tenant, $secret] = $this->webhookStore();
        TenantSetting::put($tenant->id, 'sr_enabled', '1');
        TenantSetting::put($tenant->id, 'api_token_call_centr', 'sr-token');
        TenantSetting::put($tenant->id, 'company_id_in_call_centre', '99');
        TenantSetting::put($tenant->id, 'project_id_in_call_centr', 'project-uuid');

        $this->deliveredOrder($tenant, ['housing' => '2']);
        $this->lead($secret)->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $order->forceFill(['external_id' => 'sr-1'])->saveQuietly();
        $this->assertSame('2', $order->housing);
        $this->assertSame('Минск', $order->city);

        Http::fake(fn () => Http::response([
            'data' => ['ordersFetcher' => ['orders' => [[
                'id'     => 'sr-1',
                'status' => ['id' => '2', 'name' => 'Принят'],
                'data'   => [
                    'phoneFields'     => [['value' => ['raw' => '+375291234567']]],
                    'stringFields'    => [],
                    'booleanFields'   => [],
                    'humanNameFields' => [['value' => ['firstName' => 'Иван', 'lastName' => 'Сидоров']]],
                    'addressFields'   => [['value' => [
                        'region' => '', 'city' => 'Гродно', 'postcode' => '230000',
                        'address_1' => 'Советская', 'building' => '7', 'apartment' => '3',
                    ]]],
                ],
                'cart' => ['items' => [[
                    'quantity' => 1,
                    'pricing'  => ['unitPrice' => 30],
                    'sku'      => ['item' => ['name' => 'Товар из SR']],
                ]]],
            ]]]],
        ], 200));

        (new SyncSalesRenderJob($tenant->id))->handle();

        $order = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame('Заказать', $order->status);
        $this->assertSame(['Товар из SR'], $order->goods);
        $this->assertStringContainsString('Гродно', $order->city);
        $this->assertSame('Советская', $order->street);
        $this->assertNull($order->housing);          // not a mix of two addresses
        $this->assertNull($order->customer_prefill); // banner no longer claims the base as the source
        $this->assertSame('europochta', $order->delivery_type); // SR does not return it: kept from the base

        // Confirmed SR order became the newest profile source.
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame($order->id, (int) $customer->last_order_id);
        $this->assertSame('Советская', $customer->street);
    }

    public function test_customer_base_failure_does_not_break_order_creation(): void
    {
        $tenant = $this->store();
        $this->mock(CustomerService::class, function ($mock) {
            $mock->shouldReceive('linkOrder')->andThrow(new \RuntimeException('boom'));
        });

        $order = $this->order($tenant);

        $this->assertNotNull($order->id);
    }

    // ─── Backfill ────────────────────────────────────────────────────────────

    public function test_backfill_builds_customers_from_existing_orders_and_is_idempotent(): void
    {
        $tenant = $this->store();
        $first  = $this->deliveredOrder($tenant, ['city' => 'Первый']);
        $last   = $this->deliveredOrder($tenant, ['city' => 'Последний']);
        $this->order($tenant, ['status' => 'Спам']);

        // Simulate pre-release data.
        Order::withoutGlobalScopes()->update(['customer_id' => null]);
        Customer::withoutGlobalScopes()->delete();

        $this->artisan('customers:backfill', ['--tenant' => $tenant->id])->assertExitCode(0);
        $this->artisan('customers:backfill', ['--tenant' => $tenant->id])->assertExitCode(0);

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
        $customer = Customer::withoutGlobalScopes()->first();
        $this->assertSame('Последний', $customer->city);
        $this->assertSame($last->id, (int) $customer->last_order_id);
        $this->assertSame(3, $customer->orders_count);
        $this->assertSame(3, Order::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
    }
}
