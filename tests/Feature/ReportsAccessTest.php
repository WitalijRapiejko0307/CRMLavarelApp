<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createStoreUser(string $role = 'admin', string $email = 'store-admin@example.com'): User
    {
        $tenant = Tenant::create([
            'name'                => 'Reports Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => ucfirst($role),
            'email'     => $email,
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function createCallCenterUser(string $role = 'admin', string $email = 'cc-admin@example.com'): User
    {
        $tenant = Tenant::create([
            'name'                => 'Reports CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'CC ' . ucfirst($role),
            'email'     => $email,
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function createOrder(int $tenantId, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ], $overrides));
    }

    private function reportsProps($response): array
    {
        return $response->inertiaPage()['props'];
    }

    private function funnelStep(array $props, string $key): array
    {
        $step = collect($props['funnel'])->firstWhere('key', $key);
        $this->assertNotNull($step, "Funnel step [{$key}] is missing.");

        return $step;
    }

    private function liveItem(array $props, string $key): array
    {
        $item = collect($props['live'])->firstWhere('key', $key);
        $this->assertNotNull($item, "Live slice [{$key}] is missing.");

        return $item;
    }

    public function test_store_admin_can_view_reports(): void
    {
        $user = $this->createStoreUser('admin', 'reports-admin@example.com');

        $response = $this->actingAs($user)->get('/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->has('funnel', 5)
            ->where('funnel.0.key', 'leads')
            ->where('funnel.1.key', 'formalized')
            ->where('funnel.2.key', 'shipped')
            ->where('funnel.3.key', 'ops')
            ->where('funnel.4.key', 'purchased')
            ->has('live')
            ->has('filters')
            ->has('utm_campaigns')
        );
    }

    public function test_store_manager_can_view_reports(): void
    {
        $user = $this->createStoreUser('manager', 'reports-manager@example.com');

        $response = $this->actingAs($user)->get('/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Reports/Index'));
    }

    public function test_store_operator_cannot_view_reports(): void
    {
        $user = $this->createStoreUser('operator', 'reports-operator@example.com');

        $this->actingAs($user)->get('/reports')->assertForbidden();
    }

    public function test_call_center_admin_cannot_view_reports(): void
    {
        $user = $this->createCallCenterUser('admin', 'reports-cc-admin@example.com');

        $this->actingAs($user)->get('/reports')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/reports')->assertRedirect(route('login'));
    }

    public function test_funnel_counts_statuses_and_excludes_flagged_dubl(): void
    {
        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name'      => 'Дубль Исключённый Тест',
            'status'         => 'Дубль',
            'funnel_exclude' => true,
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Спам Заявка Тест',
            'status'    => 'Спам',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Отказ Клиента Тест',
            'status'    => 'Отказ',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Забрать Деньги Тест',
            'status'    => 'Забрать деньги',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Завершен Выкуп Тест',
            'status'    => 'Завершен',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Посчитан Выкуп Тест',
            'status'    => 'Посчитан',
        ]);

        $response = $this->actingAs($user)->get('/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('funnel.0.key', 'leads')
            ->where('funnel.0.count', 5)
            ->where('funnel.4.key', 'purchased')
            ->where('funnel.4.count', 2)
        );

        $props = $this->reportsProps($response);

        $this->assertSame(5, $this->funnelStep($props, 'leads')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'formalized')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'shipped')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'ops')['count']);
        $this->assertSame(2, $this->funnelStep($props, 'purchased')['count']);
        $this->assertSame(
            3,
            $this->funnelStep($props, 'leads')['count']
            - $this->funnelStep($props, 'formalized')['count']
            - $this->funnelStep($props, 'shipped')['count']
            - $this->funnelStep($props, 'ops')['count']
            - $this->funnelStep($props, 'purchased')['count'],
            'Спам, Отказ и Забрать деньги не входят в шаги воронки'
        );
    }

    public function test_funnel_counts_current_status_not_later_stages(): void
    {
        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name' => 'Сейчас Оформлен',
            'status'    => 'Оформлен',
        ]);
        for ($i = 1; $i <= 5; $i++) {
            $this->createOrder($user->tenant_id, [
                'full_name' => "Сейчас Отправлено {$i}",
                'status'    => 'Отправлено',
            ]);
        }
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Сейчас В Отделении',
            'status'    => 'В отделении',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Сейчас Выкуп',
            'status'    => 'Завершен',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Ещё Позвонить',
            'status'    => 'Позвонить',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Ещё Отказ',
            'status'    => 'Отказ',
        ]);

        $response = $this->actingAs($user)->get('/reports');
        $props    = $this->reportsProps($response);

        $leads      = $this->funnelStep($props, 'leads')['count'];
        $formalized = $this->funnelStep($props, 'formalized')['count'];
        $shipped    = $this->funnelStep($props, 'shipped')['count'];
        $ops        = $this->funnelStep($props, 'ops')['count'];
        $purchased  = $this->funnelStep($props, 'purchased')['count'];

        $this->assertSame(10, $leads);
        $this->assertSame(1, $formalized);
        $this->assertSame(5, $shipped);
        $this->assertSame(1, $ops);
        $this->assertSame(1, $purchased);
        $this->assertSame(
            $leads,
            $formalized + $shipped + $ops + $purchased + 2,
            'Оформлено+Отправлено+ОПС+Выкуп = Заявка − статусы вне шагов'
        );
    }

    public function test_zabrat_dengi_is_not_purchased(): void
    {
        $user = $this->createStoreUser();
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Только Забрать Деньги',
            'status'    => 'Забрать деньги',
        ]);

        $response = $this->actingAs($user)->get('/reports');
        $props    = $this->reportsProps($response);

        $this->assertSame(1, $this->funnelStep($props, 'leads')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'formalized')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'shipped')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'ops')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'purchased')['count']);
    }

    public function test_date_filter_excludes_orders_outside_period_but_live_slice_is_independent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00'));

        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name'  => 'Внутри Периода Заявка',
            'status'     => 'Отказ',
            'created_at' => Carbon::parse('2026-09-10 10:00:00'),
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name'  => 'Вне Периода Заявка',
            'status'     => 'Спам',
            'created_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name'     => 'Вне Периода К Отправке',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
            'created_at'    => Carbon::parse('2026-08-02 10:00:00'),
        ]);

        $filtered = $this->actingAs($user)->get('/reports?date_from=2026-09-08&date_to=2026-09-16');
        $filtered->assertOk();
        $filtered->assertInertia(fn ($page) => $page
            ->where('filters.date_from', '2026-09-08')
            ->where('filters.date_to', '2026-09-16')
        );

        $filteredProps = $this->reportsProps($filtered);
        $this->assertSame(1, $this->funnelStep($filteredProps, 'leads')['count']);
        $this->assertSame(1, $this->liveItem($filteredProps, OrderSegment::TO_SHIP_BELPOST)['count']);

        $unfiltered = $this->actingAs($user)->get('/reports');
        $this->assertSame(3, $this->funnelStep($this->reportsProps($unfiltered), 'leads')['count']);
    }

    public function test_utm_campaign_filter_applies_and_echoes_campaigns(): void
    {
        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name'     => 'Кампания Весна',
            'status'        => 'Отказ',
            'utm_campaign'  => 'spring',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name'     => 'Кампания Зима',
            'status'        => 'Завершен',
            'utm_campaign'  => 'winter',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Без Кампании',
            'status'    => 'Спам',
        ]);

        $response = $this->actingAs($user)->get('/reports?utm_campaign=spring');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.utm_campaign', 'spring')
            ->where('funnel.0.key', 'leads')
            ->where('funnel.0.count', 1)
            ->where('funnel.4.count', 0)
        );

        $props = $this->reportsProps($response);
        $this->assertSame(['spring', 'winter'], $props['utm_campaigns']);
        $this->assertSame(1, $this->funnelStep($props, 'leads')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'purchased')['count']);
    }

    public function test_live_slice_to_ship_belpost(): void
    {
        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name'     => 'К Отправке Белпочта',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name'     => 'К Отправке Европочта',
            'status'        => 'Отправить',
            'delivery_type' => 'europochta',
        ]);

        $response = $this->actingAs($user)->get('/reports');
        $item     = $this->liveItem($this->reportsProps($response), OrderSegment::TO_SHIP_BELPOST);

        $this->assertSame(1, $item['count']);
        $this->assertSame('/orders?segment=to_ship_belpost', $item['href']);
    }

    public function test_live_slice_stuck_counts_old_at_branch_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00'));

        $user = $this->createStoreUser();

        $this->createOrder($user->tenant_id, [
            'full_name'         => 'Застрял В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => Carbon::now()->subDays(6),
        ]);
        $this->createOrder($user->tenant_id, [
            'full_name'         => 'Свежий В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => Carbon::now()->subDays(2),
        ]);

        $response = $this->actingAs($user)->get('/reports');
        $stuck    = $this->liveItem($this->reportsProps($response), OrderSegment::STUCK);

        $this->assertSame(1, $stuck['count']);
        $this->assertSame('/orders?segment=stuck', $stuck['href']);
    }

    public function test_other_tenant_orders_never_appear(): void
    {
        $user = $this->createStoreUser();
        $this->createOrder($user->tenant_id, [
            'full_name' => 'Свои Заявки Магазина',
            'status'    => 'Отказ',
        ]);

        $other = Tenant::create([
            'name'                => 'Other Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        $this->createOrder($other->id, [
            'full_name'    => 'Чужой Выкуп',
            'status'       => 'Посчитан',
            'utm_campaign' => 'foreign-campaign',
        ]);
        $this->createOrder($other->id, [
            'full_name'     => 'Чужая Отправка Бел',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
        ]);

        $response = $this->actingAs($user)->get('/reports');
        $props    = $this->reportsProps($response);

        $this->assertSame(1, $this->funnelStep($props, 'leads')['count']);
        $this->assertSame(0, $this->funnelStep($props, 'purchased')['count']);
        $this->assertSame(0, $this->liveItem($props, OrderSegment::TO_SHIP_BELPOST)['count']);
        $this->assertSame([], $props['utm_campaigns']);
    }

    public function test_store_admin_can_still_access_finances(): void
    {
        $user = $this->createStoreUser('admin', 'finances-admin@example.com');

        $this->actingAs($user)->get('/finances')->assertOk();
    }

    public function test_call_center_admin_can_still_access_analytics(): void
    {
        $user = $this->createCallCenterUser('admin', 'analytics-cc@example.com');

        $this->actingAs($user)->get('/analytics')->assertOk();
    }
}
