<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    private function createOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'full_name' => 'Заказ Фильтра Тестов',
            'status'    => 'Позвонить',
        ], $overrides));
    }

    /** @return list<string> */
    private function indexOrderNames($response): array
    {
        $html = $response->getContent();
        if (!preg_match('/data-page="([^"]*)"/', $html, $m)) {
            return [];
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return collect($page['props']['orders']['data'] ?? [])
            ->pluck('full_name')
            ->all();
    }

    private function assertIndexHasOrder($response, string $fullName): void
    {
        $this->assertContains($fullName, $this->indexOrderNames($response));
    }

    private function assertIndexMissingOrder($response, string $fullName): void
    {
        $this->assertNotContains($fullName, $this->indexOrderNames($response));
    }

    private function inertiaProp($response, string $key)
    {
        $html = $response->getContent();
        if (!preg_match('/data-page="([^"]*)"/', $html, $m)) {
            return null;
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return data_get($page['props'] ?? [], $key);
    }

    public function test_orders_index_filters_by_date_range(): void
    {
        $user = $this->createActiveTenantUser();

        $julyOrder = $this->createOrder($user, [
            'full_name'  => 'Июльский Заказ Тестов',
            'created_at' => '2026-07-15 10:00:00',
        ]);

        $augustOrder = $this->createOrder($user, [
            'full_name'  => 'Августовский Заказ Тестов',
            'created_at' => '2026-08-15 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders?date_from=2026-07-01&date_to=2026-07-31');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $julyOrder->full_name);
        $this->assertIndexMissingOrder($response, $augustOrder->full_name);
    }

    public function test_orders_index_without_date_filters_shows_all_orders(): void
    {
        $user = $this->createActiveTenantUser();

        $julyOrder = $this->createOrder($user, [
            'full_name'  => 'Июльский Заказ Тестов',
            'created_at' => '2026-07-15 10:00:00',
        ]);

        $augustOrder = $this->createOrder($user, [
            'full_name'  => 'Августовский Заказ Тестов',
            'created_at' => '2026-08-15 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $julyOrder->full_name);
        $this->assertIndexHasOrder($response, $augustOrder->full_name);
    }

    public function test_segment_stuck_returns_only_old_at_branch_orders(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $user = $this->createActiveTenantUser();

        $stuck = $this->createOrder($user, [
            'full_name'         => 'Застрявший Клиент Тестов',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDays(6),
        ]);
        $fresh = $this->createOrder($user, [
            'full_name'         => 'Свежий Отделение Тестов',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get('/orders?segment=stuck');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $stuck->full_name);
        $this->assertIndexMissingOrder($response, $fresh->full_name);
    }

    public function test_search_finds_order_by_track_number(): void
    {
        $user = $this->createActiveTenantUser();

        $tracked = $this->createOrder($user, [
            'full_name'    => 'Клиент С Треком Тестов',
            'track_number' => 'BY999TRACKTEST',
        ]);
        $other = $this->createOrder($user, [
            'full_name'    => 'Клиент Без Трека Тестов',
            'track_number' => 'AA000OTHER',
        ]);

        $response = $this->actingAs($user)->get('/orders?search=BY999TRACKTEST');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $tracked->full_name);
        $this->assertIndexMissingOrder($response, $other->full_name);
    }

    public function test_search_finds_order_by_product_name_in_goods(): void
    {
        $user = $this->createActiveTenantUser();

        $match = $this->createOrder($user, [
            'full_name' => 'Клиент С Кремом Тестов',
            'goods'     => ['УникальныйКремСегментТест'],
        ]);
        $other = $this->createOrder($user, [
            'full_name' => 'Клиент С Другим Товаром',
            'goods'     => ['СовсемДругойТовар'],
        ]);

        $response = $this->actingAs($user)->get('/orders?search='.urlencode('УникальныйКремСегментТест'));

        $response->assertOk();
        $this->assertIndexHasOrder($response, $match->full_name);
        $this->assertIndexMissingOrder($response, $other->full_name);
    }

    public function test_search_still_finds_duplicate_status_order_by_name(): void
    {
        $user = $this->createActiveTenantUser();

        $dup = $this->createOrder($user, [
            'full_name' => 'Дубль Клиент Уникальный',
            'status'    => 'Дубль',
        ]);
        $other = $this->createOrder($user, [
            'full_name' => 'Обычный Клиент Другой',
            'status'    => 'Позвонить',
        ]);

        $response = $this->actingAs($user)->get('/orders?search='.urlencode('Дубль Клиент Уникальный'));

        $response->assertOk();
        $this->assertIndexHasOrder($response, $dup->full_name);
        $this->assertIndexMissingOrder($response, $other->full_name);
    }

    public function test_to_ship_belpost_does_not_include_europochta(): void
    {
        $user = $this->createActiveTenantUser();

        $bel = $this->createOrder($user, [
            'full_name'     => 'Белпочта К Отправке',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
        ]);
        $euro = $this->createOrder($user, [
            'full_name'     => 'Европочта К Отправке',
            'status'        => 'Отправить',
            'delivery_type' => 'europochta',
        ]);

        $response = $this->actingAs($user)->get('/orders?segment=to_ship_belpost');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $bel->full_name);
        $this->assertIndexMissingOrder($response, $euro->full_name);
    }

    public function test_delivery_type_filter_works_without_segment(): void
    {
        $user = $this->createActiveTenantUser();

        $euro = $this->createOrder($user, [
            'full_name'     => 'Только Европочта Тестов',
            'delivery_type' => 'europochta',
        ]);
        $bel = $this->createOrder($user, [
            'full_name'     => 'Только Белпочта Тестов',
            'delivery_type' => 'belpost',
        ]);

        $response = $this->actingAs($user)->get('/orders?delivery_type=europochta');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $euro->full_name);
        $this->assertIndexMissingOrder($response, $bel->full_name);
    }

    public function test_unknown_segment_does_not_500_and_shows_unfiltered_list(): void
    {
        $user = $this->createActiveTenantUser();

        $first = $this->createOrder($user, [
            'full_name' => 'Первый Без Сегмента',
            'status'    => 'Позвонить',
        ]);
        $second = $this->createOrder($user, [
            'full_name' => 'Второй Без Сегмента',
            'status'    => 'Отправить',
        ]);

        $response = $this->actingAs($user)->get('/orders?segment=not_a_real_queue');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $first->full_name);
        $this->assertIndexHasOrder($response, $second->full_name);
    }

    public function test_call_center_index_applies_segment_on_assigned_orders(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');

        $store = Tenant::create([
            'name'                => 'Store Seg',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        $cc = Tenant::create([
            'name'                => 'CC Seg',
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
            'email'     => 'cc-seg@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $stuck = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'КЦ Застрявший Тестов',
            'status'                => 'В отделении',
            'status_changed_at'     => now()->subDays(6),
        ]);
        $fresh = Order::withoutGlobalScopes()->create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'КЦ Свежий Тестов',
            'status'                => 'В отделении',
            'status_changed_at'     => now()->subDay(),
        ]);

        $response = $this->actingAs($ccUser)->get('/orders?segment=stuck');

        $response->assertOk();
        $this->assertIndexHasOrder($response, $stuck->full_name);
        $this->assertIndexMissingOrder($response, $fresh->full_name);
        $this->assertSame('к отправке Бел', $this->inertiaProp($response, 'segments.to_ship_belpost'));
    }

    public function test_orders_index_default_order_is_newest_first(): void
    {
        $user = $this->createActiveTenantUser();

        $this->createOrder($user, [
            'full_name'  => 'Старый Заказ Тестов',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->createOrder($user, [
            'full_name'  => 'Новый Заказ Тестов',
            'created_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertOk();
        $this->assertSame(
            ['Новый Заказ Тестов', 'Старый Заказ Тестов'],
            $this->indexOrderNames($response)
        );
        $this->assertSame('created_at', $this->inertiaProp($response, 'filters.sort'));
        $this->assertSame('desc', $this->inertiaProp($response, 'filters.dir'));
    }

    public function test_orders_index_sorts_full_name_asc_and_desc(): void
    {
        $user = $this->createActiveTenantUser();

        $this->createOrder($user, [
            'full_name'  => 'Яковлев Яков Тестов',
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->createOrder($user, [
            'full_name'  => 'Андреев Андрей Тестов',
            'created_at' => '2026-09-02 10:00:00',
        ]);

        $asc = $this->actingAs($user)->get('/orders?sort=full_name&dir=asc');
        $asc->assertOk();
        $this->assertSame(
            ['Андреев Андрей Тестов', 'Яковлев Яков Тестов'],
            $this->indexOrderNames($asc)
        );
        $this->assertSame('full_name', $this->inertiaProp($asc, 'filters.sort'));
        $this->assertSame('asc', $this->inertiaProp($asc, 'filters.dir'));

        $desc = $this->actingAs($user)->get('/orders?sort=full_name&dir=desc');
        $desc->assertOk();
        $this->assertSame(
            ['Яковлев Яков Тестов', 'Андреев Андрей Тестов'],
            $this->indexOrderNames($desc)
        );
    }

    public function test_orders_index_unknown_sort_falls_back_to_newest_first(): void
    {
        $user = $this->createActiveTenantUser();

        $this->createOrder($user, [
            'full_name'  => 'Старый Заказ Тестов',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->createOrder($user, [
            'full_name'  => 'Новый Заказ Тестов',
            'created_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/orders?sort=not_a_column&dir=sideways');

        $response->assertOk();
        $this->assertSame(
            ['Новый Заказ Тестов', 'Старый Заказ Тестов'],
            $this->indexOrderNames($response)
        );
        $this->assertSame('created_at', $this->inertiaProp($response, 'filters.sort'));
        $this->assertSame('desc', $this->inertiaProp($response, 'filters.dir'));
    }
}
