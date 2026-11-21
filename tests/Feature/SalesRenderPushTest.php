<?php

namespace Tests\Feature;

use App\Jobs\PushOrderToSalesRenderJob;
use App\Jobs\SyncSalesRenderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\SalesRenderService;
use App\Services\SrConnectionCheckService;
use App\Services\SrHealthService;
use App\Services\SrPushService;
use App\Support\SrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sending CRM edits of exported orders to SalesRender (docs/features/salesrender-push-updates.md).
 */
class SalesRenderPushTest extends TestCase
{
    use RefreshDatabase;

    /** Mutable stand-in for the SalesRender order. */
    private array $sr;

    /** Requests the fake received, as [operation, variables]. */
    private array $calls = [];

    /** SalesRender project without the closing statuses (like the trial account). */
    private bool $noStatuses = false;

    /** When set, every request gets this raw response instead of the stateful fake. */
    private $forced = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->sr = [
            'id'     => 'sr-1',
            'status' => 'Processing',
            'phone'  => '375291234567',
            'first'  => '',
            'last'   => 'Иванов Иван',
            'cart'   => [['name' => 'Коврик', 'qty' => 1, 'price' => 55.0, 'var' => null]],
        ];
        $this->calls = [];
        $this->forced = null;
        $this->noStatuses = false;
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    /** @var mixed real queue manager, restored by unfakeQueue() */
    private $realQueue;

    /** $this->httpFake() appends stubs (the first match wins); start from a clean factory every time. */
    private function httpFake($stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($stubs);
    }

    private function fakeQueue(): void
    {
        $this->realQueue = $this->realQueue ?: app('queue');
        Queue::fake();
    }

    private function unfakeQueue(): void
    {
        Queue::swap($this->realQueue);
    }

    private function tenant(array $settings = []): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'Push Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        foreach (array_merge([
            'sr_enabled'                => '1',
            'sr_push_enabled'           => '1',
            'sr_push_cart'              => '1',
            'api_token_call_centr'      => 'sr-token',
            'company_id_in_call_centre' => '99',
            'project_id_in_call_centr'  => 'project-uuid',
        ], $settings) as $k => $v) {
            TenantSetting::put($tenant->id, $k, $v);
        }

        return $tenant;
    }

    private function user(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'push-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function order(Tenant $tenant, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'   => $tenant->id,
            'full_name'   => 'Иванов Иван',
            'status'      => 'Позвонить',
            'phone'       => '375291234567',
            'external_id' => 'sr-1',
            'goods'       => ['Коврик'],
            'quantities'  => [1],
            'prices'      => [55],
        ], $overrides));
    }

    private function product(Tenant $tenant, string $name = 'Коврик', int $srItemId = 7): Product
    {
        return Product::withoutGlobalScopes()->create([
            'tenant_id'  => $tenant->id,
            'name'       => $name,
            'sr_item_id' => $srItemId,
        ]);
    }

    /** SalesRender order as ordersFetcher returns it. */
    private function srOrderPayload(): array
    {
        $items = [];
        foreach ($this->sr['cart'] as $i => $line) {
            $items[] = [
                'id'       => $i + 1,
                'quantity' => $line['qty'],
                'pricing'  => ['unitPrice' => $line['price']],
                'sku'      => ['item' => ['id' => 7, 'name' => $line['name']], 'variation' => ['number' => $line['var'] ?? 1, 'property' => null]],
            ];
        }

        return [
            'id'     => $this->sr['id'],
            'status' => ['id' => '1', 'name' => $this->sr['status']],
            'data'   => [
                'stringFields'    => [],
                'booleanFields'   => [],
                'phoneFields'     => [['value' => ['raw' => $this->sr['phone']]]],
                'addressFields'   => [],
                'humanNameFields' => [['value' => ['firstName' => $this->sr['first'], 'lastName' => $this->sr['last']]]],
            ],
            'cart'   => ['items' => $items],
        ];
    }

    /**
     * Stateful SalesRender: updateOrder mutates $this->sr, so read-back verification can succeed.
     *
     * @param  bool  $ignoreNameField  simulate SalesRender silently ignoring an unknown field id
     */
    private function fakeSalesRender(bool $ignoreNameField = false): void
    {
        $this->httpFake(function ($request) use ($ignoreNameField) {
            if ($this->forced !== null) {
                return $this->forced;
            }

            $body  = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'statusesFetcher')) {
                $this->calls[] = ['statuses', []];

                $list = [['id' => '1', 'name' => 'Processing']];
                if (!$this->noStatuses) {
                    $list = array_merge($list, [
                        ['id' => '2', 'name' => 'Принят'], ['id' => '3', 'name' => 'Отменен'],
                        ['id' => '4', 'name' => 'Дубли'], ['id' => '5', 'name' => 'Спам'],
                    ]);
                }

                return Http::response(['data' => ['statusesFetcher' => ['statuses' => $list]]], 200);
            }

            if (str_contains($query, 'orderFieldsFetcher')) {
                return Http::response(['data' => ['orderFieldsFetcher' => ['fields' => [
                    ['id' => 'phone', 'name' => 'phone', 'label' => 'Phone'], ['id' => 'name', 'name' => 'name', 'label' => 'Name'],
                ]]]], 200);
            }

            if (str_contains($query, 'updateOrder')) {
                $input = $body['variables']['input'] ?? [];
                $this->calls[] = ['updateOrder', $input];

                if (($input['id'] ?? '') === '0') {
                    return Http::response(['errors' => [['message' => 'Order not found']], 'data' => ['orderMutation' => ['updateOrder' => null]]], 200);
                }

                foreach ($input['orderData']['phoneFields'] ?? [] as $f) {
                    $this->sr['phone'] = $f['value'];
                }
                if (!$ignoreNameField) {
                    foreach ($input['orderData']['humanNameFields'] ?? [] as $f) {
                        $this->sr['first'] = $f['value']['firstName'] ?? '';
                        $this->sr['last']  = $f['value']['lastName'] ?? '';
                    }
                }
                if (isset($input['cart']['items'])) {
                    $this->sr['cart'] = array_map(fn ($i) => [
                        'name' => 'Коврик', 'qty' => $i['quantity'], 'price' => $i['pricing']['unitPrice'], 'var' => $i['variation'] > 1 ? $i['variation'] : null,
                    ], $input['cart']['items']);
                }
                if (isset($input['statusId'])) {
                    $this->sr['status'] = [1 => 'Processing', 2 => 'Принят', 3 => 'Отменен', 4 => 'Дубли', 5 => 'Спам'][(int) $input['statusId']];
                }

                return Http::response(['data' => ['orderMutation' => ['updateOrder' => ['id' => $this->sr['id']]]]], 200);
            }

            if (str_contains($query, 'ordersFetcher')) {
                $this->calls[] = ['fetch', []];
                $ids = $query;

                return Http::response(['data' => ['ordersFetcher' => [
                    'orders' => str_contains($ids, '"0"') ? [] : [$this->srOrderPayload()],
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    private function restricted(string $type, string $field): array
    {
        return ['errors' => [[
            'message'    => 'Field restricted by role',
            'extensions' => ['category' => 'ROLE', 'code' => 'FIELD_RESTRICTED_BY_ROLE', 'restriction' => ['type' => $type, 'field' => $field]],
        ]], 'data' => []];
    }

    private function updateCalls(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'updateOrder'));
    }

    /** Edit like OrderController does: snapshot, change, track. */
    private function edit(Order $order, array $attrs, ?int $userId = null): array
    {
        $svc    = app(SrPushService::class);
        $before = $svc->snapshot($order);
        $order->update($attrs);

        return $svc->track($order, $before, $userId);
    }

    private function runPush(Order $order, bool $force = false, bool $ignoreHealth = false): Order
    {
        (new PushOrderToSalesRenderJob($order->id, $force, $ignoreHealth))->handle(app(SrPushService::class));

        return $order->fresh();
    }

    // ─── Result classification (SalesRenderService) ───────────────────────────

    public function test_permission_error_comes_with_http_200_and_is_classified(): void
    {
        $tenant = $this->tenant();
        $this->httpFake(['*' => Http::response($this->restricted('OrderMutation', 'updateOrder'), 200)]);

        $service = new SalesRenderService('t', '99', 'p', $tenant->id);
        $result  = $service->updateOrder('sr-1', ['orderData' => []]);

        $this->assertFalse($result->ok);
        $this->assertSame(SrResult::PERMISSION_DENIED, $result->errorCode);
        $this->assertSame('OrderMutation.updateOrder', $result->restrictionKey());
        $this->assertTrue($result->isAccessProblem());
        $this->assertSame('permission_denied', SrHealthService::get($tenant->id)['state']);
        $this->assertSame(['OrderMutation.updateOrder'], SrHealthService::get($tenant->id)['missing']);
    }

    public function test_http_errors_are_classified(): void
    {
        $tenant  = $this->tenant();
        $service = new SalesRenderService('t', '99', 'p', $tenant->id);

        $this->httpFake(['*' => Http::response(['errors' => [['message' => 'Invalid token', 'extensions' => ['code' => 401]]]], 401)]);
        $this->assertSame(SrResult::AUTH_INVALID, $service->fetchOrderResult('sr-1')->errorCode);
        $this->assertSame('auth_failed', SrHealthService::get($tenant->id)['state']);

        $this->httpFake(['*' => Http::response(['errors' => [['message' => 'x', 'extensions' => ['code' => 'COMPANY_NOT_FOUND']]]], 404)]);
        $this->assertSame(SrResult::CONFIG_COMPANY, $service->fetchOrderResult('sr-1')->errorCode);

        $this->httpFake(['*' => Http::response('boom', 503)]);
        $result = $service->fetchOrderResult('sr-1');
        $this->assertSame(SrResult::TRANSIENT, $result->errorCode);
        $this->assertTrue($result->isTransient());
    }

    public function test_salesrender_rate_limit_is_retried_and_price_range_error_is_explained(): void
    {
        $tenant  = $this->tenant();
        $service = new SalesRenderService('t', '99', 'p', $tenant->id);

        $this->httpFake(['*' => Http::response(['errors' => [['message' => 'Too many updates of same entity']], 'data' => []], 200)]);
        $result = $service->updateOrder('sr-1', ['orderData' => []]);
        $this->assertSame(SrResult::TRANSIENT, $result->errorCode);
        $this->assertTrue($result->isTransient());

        $this->httpFake(['*' => Http::response(['errors' => [['message' => "Cart item with id '1' can not be changed, because its price is non actual"]], 'data' => []], 200)]);
        $result = $service->updateOrder('sr-1', ['cart' => ['items' => []]]);
        $this->assertSame(SrResult::SR_REJECTED, $result->errorCode);
        $this->assertStringContainsString('диапазона', $result->message);
        $this->assertStringNotContainsString('non actual', $result->message);
    }

    public function test_existing_fetch_reports_missing_read_permission_instead_of_silent_skip(): void
    {
        $tenant = $this->tenant();
        $this->httpFake(['*' => Http::response($this->restricted('Query', 'ordersFetcher'), 200)]);

        $service = new SalesRenderService('t', '99', 'p', $tenant->id);

        $this->assertNull($service->fetchOrder('sr-1'));
        $this->assertSame(['Query.ordersFetcher'], SrHealthService::get($tenant->id)['missing']);
    }

    public function test_successful_request_clears_the_missing_mark(): void
    {
        $tenant = $this->tenant();
        $service = new SalesRenderService('t', '99', 'p', $tenant->id);

        $this->httpFake(['*' => Http::response($this->restricted('Query', 'ordersFetcher'), 200)]);
        $service->fetchOrder('sr-1');
        $this->assertSame('permission_denied', SrHealthService::get($tenant->id)['state']);

        $this->fakeSalesRender();
        $service->fetchOrder('sr-1');
        $this->assertSame('ok', SrHealthService::get($tenant->id)['state']);
    }

    // ─── Tracking edits ───────────────────────────────────────────────────────

    public function test_edit_is_recorded_and_push_is_queued(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        $notice = $this->edit($order, ['phone' => '375299998877', 'full_name' => 'Петров Пётр'], 5);

        $order = $order->fresh();
        $this->assertSame('pending', $order->sr_sync_state);
        $this->assertSame(['phone', 'full_name'], array_keys($order->sr_pending_changes));
        $this->assertSame('375291234567', $order->sr_pending_changes['phone']['from']);
        $this->assertSame('375299998877', $order->sr_pending_changes['phone']['to']);
        $this->assertTrue($notice['queued']);
        $this->assertSame('info', $notice['level']);
        Queue::assertPushed(PushOrderToSalesRenderJob::class);
    }

    public function test_second_edit_keeps_the_original_from_value(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        $this->edit($order, ['phone' => '375299998877']);
        $this->edit($order->fresh(), ['phone' => '375291112233']);

        $entry = $order->fresh()->sr_pending_changes['phone'];
        $this->assertSame('375291234567', $entry['from']);
        $this->assertSame('375291112233', $entry['to']);
    }

    public function test_reverting_an_edit_drops_the_pending_change(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        $this->edit($order, ['phone' => '375299998877']);
        $this->edit($order->fresh(), ['phone' => '375291234567']);

        $order = $order->fresh();
        $this->assertEmpty($order->sr_pending_changes);
        $this->assertSame('synced', $order->sr_sync_state);
    }

    public function test_nothing_is_tracked_when_push_is_off_or_order_was_not_exported(): void
    {
        $this->fakeQueue();

        $off = $this->tenant(['sr_push_enabled' => '']);
        $o1  = $this->order($off);
        $this->assertSame('none', $this->edit($o1, ['phone' => '375299998877'])['level']);
        $this->assertNull($o1->fresh()->sr_sync_state);

        $on = $this->tenant();
        $o2 = $this->order($on, ['external_id' => null]);
        $this->assertSame('none', $this->edit($o2, ['phone' => '375299998877'])['level']);

        $o3 = $this->order($on, ['status' => 'Отправлено']);
        $this->assertSame('none', $this->edit($o3, ['phone' => '375299998877'])['level']);

        Queue::assertNothingPushed();
    }

    public function test_known_permission_problem_blocks_without_queueing_and_warns(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        SrHealthService::record($tenant->id, 'updateOrder', SrResult::failure(
            SrResult::PERMISSION_DENIED, 200, null, ['type' => 'OrderMutation', 'field' => 'updateOrder']
        ));

        $notice = $this->edit($order, ['phone' => '375299998877']);

        $order = $order->fresh();
        $this->assertSame('warning', $notice['level']);
        $this->assertStringContainsString('не отправлено', $notice['message']);
        $this->assertSame('blocked', $order->sr_sync_state);
        $this->assertSame(SrResult::PERMISSION_DENIED, $order->sr_last_error_code);
        $this->assertNotEmpty($order->sr_pending_changes, 'the edit must not be lost');
        Queue::assertNothingPushed();
    }

    public function test_status_without_pair_is_not_sent_and_user_is_told(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        $notice = $this->edit($order, ['status' => 'Перезвонить']);

        $this->assertSame('info', $notice['level']);
        $this->assertStringContainsString('не передаётся', $notice['message']);
        $this->assertEmpty($order->fresh()->sr_pending_changes);
        Queue::assertNothingPushed();
    }

    public function test_cart_edits_are_not_sent_when_cart_push_is_off(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant(['sr_push_cart' => '']);
        $order  = $this->order($tenant);

        $notice = $this->edit($order, ['quantities' => [3]]);

        $this->assertStringContainsString('корзины не отправляются', $notice['message']);
        $this->assertEmpty($order->fresh()->sr_pending_changes);
    }

    // ─── Push ─────────────────────────────────────────────────────────────────

    public function test_push_sends_phone_and_name_and_confirms_by_reading_back(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877', 'full_name' => 'Петров Пётр']);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('synced', $order->sr_sync_state);
        $this->assertEmpty($order->sr_pending_changes);
        $this->assertNotNull($order->sr_last_synced_at);

        $update = $this->updateCalls()[0][1];
        $this->assertSame('sr-1', $update['id']);
        $this->assertSame('375299998877', $update['orderData']['phoneFields'][0]['value']);
        $this->assertSame('phone', $update['orderData']['phoneFields'][0]['field']);
        $this->assertSame('Петров Пётр', $update['orderData']['humanNameFields'][0]['value']['lastName']);
        $this->assertSame('375299998877', $this->sr['phone']);
        $this->assertDatabaseHas('order_sr_pushes', ['order_id' => $order->id, 'status' => 'success']);
    }

    public function test_push_sends_cart_with_item_ids_and_pricing(): void
    {
        $tenant = $this->tenant();
        $this->product($tenant);
        $order = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['quantities' => [3], 'prices' => [60]]);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('synced', $order->sr_sync_state);
        $item = $this->updateCalls()[0][1]['cart']['items'][0];
        $this->assertSame(7, $item['itemId']);
        $this->assertSame(1, $item['variation']);
        $this->assertSame(3, $item['quantity']);
        $this->assertEquals(60.0, $item['pricing']['unitPrice']);
    }

    public function test_cart_with_unlinked_product_fails_with_a_clear_message(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant); // no Product with sr_item_id
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['quantities' => [2]]);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('failed', $order->sr_sync_state);
        $this->assertSame(SrResult::CART_ITEM_UNMAPPED, $order->sr_last_error_code);
        $this->assertStringContainsString('Коврик', $order->sr_last_error_message);
        $this->assertSame([], $this->updateCalls(), 'nothing is sent on a partial cart');
    }

    public function test_status_is_sent_with_the_status_id_from_salesrender(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['status' => 'Дубль']);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('synced', $order->sr_sync_state);
        $this->assertSame('4', $this->updateCalls()[0][1]['statusId']);
        $this->assertSame('Дубли', $this->sr['status']);
    }

    public function test_one_undeliverable_field_does_not_block_the_others(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();
        $this->noStatuses = true;

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);
        $order = $order->fresh();
        $this->edit($order, ['status' => 'Спам']);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('375299998877', $this->sr['phone'], 'the phone was delivered');
        $this->assertSame('failed', $order->sr_sync_state);
        $this->assertSame(SrResult::STATUS_NOT_FOUND_IN_SR, $order->sr_last_error_code);
        $this->assertSame(['status'], array_keys($order->sr_pending_changes), 'only the status stays pending');
        $this->assertArrayNotHasKey('statusId', $this->updateCalls()[0][1]);
    }

    public function test_silently_ignored_field_is_detected_by_read_back(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender(true);

        $this->fakeQueue();
        $this->edit($order, ['full_name' => 'Петров Пётр']);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('failed', $order->sr_sync_state);
        $this->assertSame(SrResult::FIELD_NOT_APPLIED, $order->sr_last_error_code);
        $this->assertStringContainsString('ФИО', $order->sr_last_error_message);
        $this->assertNotEmpty($order->sr_pending_changes, 'the edit stays pending');
    }

    public function test_empty_value_in_salesrender_is_pushed_not_treated_as_a_conflict(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender(true); // the project has no name field: SalesRender ignores it
        $this->sr['last'] = '';        // so the order holds no name at all

        $this->fakeQueue();
        $this->edit($order, ['full_name' => 'Петров Пётр']);
        $this->unfakeQueue();

        $order = $this->runPush($order->fresh());

        $this->assertSame('failed', $order->sr_sync_state, 'reported as not applied, not as a conflict');
        $this->assertSame(SrResult::FIELD_NOT_APPLIED, $order->sr_last_error_code);
        $this->assertNotEmpty($this->updateCalls());
    }

    public function test_independent_change_by_call_center_becomes_a_conflict_and_is_not_overwritten(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);
        $this->unfakeQueue();

        $this->sr['phone'] = '375295550000'; // the call center changed it meanwhile

        $order = $this->runPush($order->fresh());

        $this->assertSame('conflict', $order->sr_sync_state);
        $this->assertSame([], $this->updateCalls(), 'no updateOrder on conflict');
        $this->assertTrue($order->sr_pending_changes['phone']['conflict']);
        $this->assertSame('375295550000', $order->sr_pending_changes['phone']['sr']);
        $this->assertSame('375295550000', $this->sr['phone']);
    }

    public function test_keep_local_pushes_over_the_conflict_and_accept_sr_takes_salesrender(): void
    {
        $tenant = $this->tenant();
        $user   = $this->user($tenant);

        // keep_local
        $order = $this->order($tenant);
        $this->fakeSalesRender();
        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);
        $this->unfakeQueue();
        $this->sr['phone'] = '375295550000';
        $order = $this->runPush($order->fresh());
        $this->assertSame('conflict', $order->sr_sync_state);

        app(SrPushService::class)->resolve($order, 'keep_local', $user->id);
        // resolve() dispatches the job; Queue is not faked → sync driver already ran it
        $order = $order->fresh();
        $this->assertSame('synced', $order->sr_sync_state);
        $this->assertSame('375299998877', $this->sr['phone']);

        // accept_sr
        $order2 = $this->order($tenant, ['external_id' => 'sr-1', 'phone' => '375299998877']);
        $this->fakeQueue();
        $this->edit($order2, ['phone' => '291112233']);
        $this->unfakeQueue();
        $this->sr['phone'] = '375295550000';
        $order2 = $this->runPush($order2->fresh());
        $this->assertSame('conflict', $order2->sr_sync_state);

        $this->assertNull(app(SrPushService::class)->resolve($order2, 'accept_sr', $user->id));
        $order2 = $order2->fresh();
        $this->assertSame('synced', $order2->sr_sync_state);
        $this->assertStringEndsWith('295550000', $order2->phone);
    }

    public function test_value_already_in_salesrender_is_acknowledged_without_a_request(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);
        $this->unfakeQueue();

        $this->sr['phone'] = '375299998877'; // the call center typed the same number

        $order = $this->runPush($order->fresh());

        $this->assertSame('synced', $order->sr_sync_state);
        $this->assertSame([], $this->updateCalls());
    }

    public function test_permission_error_during_push_blocks_and_updates_health(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);
        $this->unfakeQueue();

        // reading works, updating is restricted
        $this->httpFake(function ($request) {
            $q = (string) ($request->data()['query'] ?? '');
            if (str_contains($q, 'updateOrder')) {
                return Http::response($this->restricted('OrderMutation', 'updateOrder'), 200);
            }

            return Http::response(['data' => ['ordersFetcher' => ['orders' => [$this->srOrderPayload()]]]], 200);
        });

        $order = $this->runPush($order->fresh());

        $this->assertSame('blocked', $order->sr_sync_state);
        $this->assertSame(SrResult::PERMISSION_DENIED, $order->sr_last_error_code);
        $this->assertNotEmpty($order->sr_pending_changes);
        $health = SrHealthService::get($tenant->id);
        $this->assertSame('permission_denied', $health['state']);
        $this->assertStringContainsString('orderMutation.updateOrder', SrHealthService::requestText($tenant->id));
        $this->assertStringNotContainsString('sr-token', SrHealthService::requestText($tenant->id));
    }

    public function test_transient_error_schedules_retries_and_gives_up_after_the_limit(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);

        $this->httpFake(['*' => Http::response('down', 503)]);

        $order = $this->runPush($order->fresh());
        $this->assertSame('retrying', $order->sr_sync_state);
        $this->assertSame(1, $order->sr_push_attempts);

        for ($i = 0; $i < 3; $i++) {
            $order = $this->runPush($order);
        }
        $this->assertSame('retrying', $order->sr_sync_state);
        $this->assertSame(4, $order->sr_push_attempts);

        $order = $this->runPush($order); // 5th failure: out of retries
        $this->assertSame('failed', $order->sr_sync_state);
        $this->assertNotEmpty($order->sr_pending_changes);
    }

    public function test_pull_does_not_overwrite_a_pending_cart_edit(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();

        $this->fakeQueue();
        $this->edit($order, ['quantities' => [3]]);

        // SalesRender still has quantity 1; the old pull job would have restored it.
        (new SyncSalesRenderJob($tenant->id))->handle();

        $order = $order->fresh();
        $this->assertSame([3], $order->quantities);
        $this->assertNotEmpty($order->sr_pending_changes);
    }

    public function test_pull_validation_accepts_the_old_phone_while_the_edit_is_unsent(): void
    {
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();
        $this->sr['status'] = 'Принят';

        $this->fakeQueue();
        $this->edit($order, ['phone' => '299998877']);

        (new SyncSalesRenderJob($tenant->id))->handle();

        $order = $order->fresh();
        $this->assertSame('Заказать', $order->status, 'no VALIDATION failure for the unsent phone');
        $this->assertSame([], json_decode((string) TenantSetting::get('sr_last_sync_failures'), true));
        $this->assertStringEndsWith('299998877', $order->phone);
    }

    // ─── Controllers & settings ───────────────────────────────────────────────

    public function test_order_update_request_flashes_that_changes_are_being_sent(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $user   = $this->user($tenant);
        $order  = $this->order($tenant);

        $response = $this->actingAs($user)->put("/orders/{$order->id}", ['phone' => '+375299998877']);

        $response->assertSessionHas('message');
        $this->assertStringContainsString('SalesRender', session('message'));
        $this->assertSame('pending', $order->fresh()->sr_sync_state);
        Queue::assertPushed(PushOrderToSalesRenderJob::class);
    }

    public function test_order_update_flashes_an_error_when_the_token_has_no_rights(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $user   = $this->user($tenant);
        $order  = $this->order($tenant);

        SrHealthService::record($tenant->id, 'updateOrder', SrResult::failure(
            SrResult::PERMISSION_DENIED, 200, null, ['type' => 'OrderMutation', 'field' => 'updateOrder']
        ));

        $response = $this->actingAs($user)->put("/orders/{$order->id}", ['phone' => '+375299998877']);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('не отправлено', session('error'));
        $this->assertSame('+375299998877', '+' . $order->fresh()->phone, 'the CRM edit is saved anyway');
    }

    public function test_status_update_tracks_a_mapped_status(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $user   = $this->user($tenant);
        $order  = $this->order($tenant);

        $this->actingAs($user)->patch("/orders/{$order->id}/status", ['status' => 'Спам'])->assertRedirect();

        $this->assertSame('Спам', $order->fresh()->sr_pending_changes['status']['to']);
        Queue::assertPushed(PushOrderToSalesRenderJob::class);
    }

    public function test_shared_integration_state_and_retry_endpoint(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $user   = $this->user($tenant);
        $order  = $this->order($tenant);

        SrHealthService::record($tenant->id, 'updateOrder', SrResult::failure(
            SrResult::PERMISSION_DENIED, 200, null, ['type' => 'OrderMutation', 'field' => 'updateOrder']
        ));
        $this->edit($order, ['phone' => '299998877']);

        $shared = SrHealthService::shared($tenant->id);
        $this->assertSame('permission_denied', $shared['health']['state']);
        $this->assertSame(1, $shared['problem_orders']['blocked']);
        $this->assertNotEmpty($shared['request_text']);

        $this->actingAs($user)->post("/api/orders/{$order->id}/sr-push/retry")->assertOk();
        $this->assertSame('pending', $order->fresh()->sr_sync_state);
        Queue::assertPushed(PushOrderToSalesRenderJob::class);
    }

    public function test_retry_without_pending_changes_is_rejected_and_other_tenants_cannot_touch_the_order(): void
    {
        $tenant = $this->tenant();
        $user   = $this->user($tenant);
        $order  = $this->order($tenant);

        $this->actingAs($user)->post("/api/orders/{$order->id}/sr-push/retry")->assertStatus(422);

        $other     = $this->tenant();
        $otherUser = $this->user($other);
        $this->actingAs($otherUser)->post("/api/orders/{$order->id}/sr-push/retry")->assertStatus(404);
    }

    public function test_push_cannot_be_enabled_before_a_successful_connection_check(): void
    {
        $tenant = $this->tenant(['sr_push_enabled' => '']);
        $user   = $this->user($tenant);

        $this->actingAs($user)
            ->post('/settings', ['settings' => ['sr_push_enabled' => '1']])
            ->assertSessionHasErrors('settings.sr_push_enabled');

        TenantSetting::put($tenant->id, 'sr_check_ok_at', now()->toIso8601String());

        $this->actingAs($user)
            ->post('/settings', ['settings' => ['sr_push_enabled' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertSame('1', TenantSetting::get('sr_push_enabled'));
    }

    public function test_connection_check_reports_missing_update_right(): void
    {
        $tenant = $this->tenant(['sr_push_enabled' => '']);
        $user   = $this->user($tenant);

        $this->httpFake(function ($request) {
            $q = (string) ($request->data()['query'] ?? '');
            if (str_contains($q, 'updateOrder')) {
                return Http::response($this->restricted('OrderMutation', 'updateOrder'), 200);
            }
            if (str_contains($q, 'statusesFetcher')) {
                return Http::response(['data' => ['statusesFetcher' => ['statuses' => [['id' => '2', 'name' => 'Принят']]]]], 200);
            }
            if (str_contains($q, 'orderFieldsFetcher')) {
                return Http::response(['data' => ['orderFieldsFetcher' => ['fields' => [['id' => 'phone', 'name' => 'phone', 'label' => 'P']]]]], 200);
            }

            return Http::response(['data' => ['ordersFetcher' => ['orders' => []]]], 200);
        });

        $response = $this->actingAs($user)->post('/settings/salesrender/check')->assertOk();

        $response->assertJsonPath('ok', false);
        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertSame('ok', $checks['read']['status']);
        $this->assertSame('fail', $checks['update']['status']);
        $this->assertSame('warn', $checks['statuses']['status']);
        $this->assertSame('warn', $checks['fields']['status']);
        $this->assertNotNull($response->json('request_text'));
        $this->assertSame('', (string) TenantSetting::get('sr_check_ok_at'));
    }

    public function test_connection_check_passes_and_clears_health_when_rights_exist(): void
    {
        $tenant = $this->tenant(['sr_push_enabled' => '']);
        $this->fakeSalesRender();

        SrHealthService::record($tenant->id, 'updateOrder', SrResult::failure(
            SrResult::PERMISSION_DENIED, 200, null, ['type' => 'OrderMutation', 'field' => 'updateOrder']
        ));

        $result = app(SrConnectionCheckService::class)->run($tenant->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', SrHealthService::get($tenant->id)['state']);
        $this->assertNotSame('', (string) TenantSetting::get('sr_check_ok_at'));
    }

    public function test_changing_the_token_resets_health_and_the_check_mark(): void
    {
        $tenant = $this->tenant();
        $user   = $this->user($tenant);

        TenantSetting::put($tenant->id, 'sr_check_ok_at', now()->toIso8601String());
        SrHealthService::record($tenant->id, 'updateOrder', SrResult::failure(
            SrResult::PERMISSION_DENIED, 200, null, ['type' => 'OrderMutation', 'field' => 'updateOrder']
        ));

        $this->actingAs($user)->post('/settings', ['settings' => ['api_token_call_centr' => 'new-token']])->assertSessionHasNoErrors();

        $this->assertSame('ok', SrHealthService::get($tenant->id)['state']);
        $this->assertSame('', (string) TenantSetting::get('sr_check_ok_at'));
    }

    public function test_sync_job_does_not_schedule_pushes(): void
    {
        $this->fakeQueue();
        $tenant = $this->tenant();
        $order  = $this->order($tenant);
        $this->fakeSalesRender();
        $this->sr['cart'] = [['name' => 'Другой', 'qty' => 2, 'price' => 10.0, 'var' => null]];

        (new SyncSalesRenderJob($tenant->id))->handle();

        $this->assertSame(['Другой'], $order->fresh()->goods);
        Queue::assertNothingPushed();
        $this->assertNull($order->fresh()->sr_sync_state);
    }
}
