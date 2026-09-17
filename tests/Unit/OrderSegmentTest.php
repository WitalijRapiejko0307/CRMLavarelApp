<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Tenant;
use App\Support\OrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderSegmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name'                => 'Segment Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
    }

    private function order(int $tenantId, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'full_name' => 'Клиент Сегмента Тестов',
            'status'    => 'Позвонить',
        ], $overrides));
    }

    public function test_labels_cover_all_slugs_in_russian(): void
    {
        $labels = OrderSegment::labels();

        $this->assertSame('к отправке Бел', $labels[OrderSegment::TO_SHIP_BELPOST]);
        $this->assertSame('к отправке Евро', $labels[OrderSegment::TO_SHIP_EUROPOCHTA]);
        $this->assertSame('без трека', $labels[OrderSegment::NO_TRACK]);
        $this->assertSame('в пути', $labels[OrderSegment::IN_TRANSIT]);
        $this->assertSame('≤5 дней', $labels[OrderSegment::AT_BRANCH]);
        $this->assertSame('>5 дней', $labels[OrderSegment::STUCK]);
        $this->assertSame('возвраты', $labels[OrderSegment::RETURNS]);
        $this->assertSame('перезвон', $labels[OrderSegment::CALLBACK_DUE]);
        $this->assertSame('перезвон', $labels[OrderSegment::CALLBACK_DUE]);
    }

    public function test_unknown_or_empty_slug_is_noop(): void
    {
        $base = Order::withoutGlobalScopes()->orderByDesc('created_at');
        $sql  = $base->toSql();

        $this->assertSame($sql, OrderSegment::apply(clone $base, '')->toSql());
        $this->assertSame($sql, OrderSegment::apply(clone $base, 'not_a_real_segment')->toSql());
    }

    public function test_to_ship_belpost_excludes_europochta(): void
    {
        $tenant = $this->tenant();

        $bel = $this->order($tenant->id, [
            'full_name'     => 'Бел К Отправке',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
        ]);
        $this->order($tenant->id, [
            'full_name'     => 'Евро К Отправке',
            'status'        => 'Отправить',
            'delivery_type' => 'europochta',
        ]);

        $ids = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::TO_SHIP_BELPOST)
            ->pluck('id')
            ->all();

        $this->assertSame([$bel->id], $ids);
    }

    public function test_stuck_excludes_fresh_and_null_status_changed_at(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $tenant = $this->tenant();

        $stuck = $this->order($tenant->id, [
            'full_name'         => 'Застрял В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDays(6),
        ]);
        $this->order($tenant->id, [
            'full_name'         => 'Вчера В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDay(),
        ]);
        $this->order($tenant->id, [
            'full_name'         => 'Без Даты Отделения',
            'status'            => 'В отделении',
            'status_changed_at' => null,
        ]);

        $ids = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::STUCK)
            ->pluck('id')
            ->all();

        $this->assertSame([$stuck->id], $ids);
    }

    public function test_at_branch_requires_recent_status_changed_at(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $tenant = $this->tenant();

        $fresh = $this->order($tenant->id, [
            'full_name'         => 'Свежий В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDay(),
        ]);
        $this->order($tenant->id, [
            'full_name'         => 'Старый В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => now()->subDays(6),
        ]);
        $this->order($tenant->id, [
            'full_name'         => 'Null В Отделении',
            'status'            => 'В отделении',
            'status_changed_at' => null,
        ]);

        $ids = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::AT_BRANCH)
            ->pluck('id')
            ->all();

        $this->assertSame([$fresh->id], $ids);
    }

    public function test_no_track_skips_call_center_and_return_in_transit(): void
    {
        $tenant = $this->tenant();

        $plain = $this->order($tenant->id, [
            'full_name'    => 'Без Трека Отправить',
            'status'       => 'Отправить',
            'track_number' => null,
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Позвонить Без Трека',
            'status'       => 'Позвонить',
            'track_number' => null,
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Возврат В Пути Без Трека',
            'status'       => 'Возврат в пути',
            'track_number' => '',
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Отправить С Треком',
            'status'       => 'Отправить',
            'track_number' => 'BY111',
        ]);

        $this->assertNotContains('Позвонить', OrderSegment::noTrackStatuses());
        $this->assertNotContains('Возврат в пути', OrderSegment::noTrackStatuses());

        $ids = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::NO_TRACK)
            ->pluck('id')
            ->all();

        $this->assertSame([$plain->id], $ids);
    }

    public function test_in_transit_and_returns_match_listed_statuses(): void
    {
        $tenant = $this->tenant();

        $shipped = $this->order($tenant->id, [
            'full_name' => 'Отправленный',
            'status'    => 'Отправлено',
        ]);
        $handed = $this->order($tenant->id, [
            'full_name' => 'На Почте',
            'status'    => 'Передан на почту',
        ]);
        $ret = $this->order($tenant->id, [
            'full_name' => 'Возврат Локальный',
            'status'    => 'Возврат',
        ]);
        $retTransit = $this->order($tenant->id, [
            'full_name' => 'Возврат В Пути',
            'status'    => 'Возврат в пути',
        ]);
        $this->order($tenant->id, [
            'full_name' => 'В Отделении Не Транзит',
            'status'    => 'В отделении',
        ]);

        $transitIds = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::IN_TRANSIT)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $returnIds = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::RETURNS)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([$shipped->id, $handed->id], $transitIds);
        $this->assertEqualsCanonicalizing([$ret->id, $retTransit->id], $returnIds);
    }

    public function test_callback_due_includes_past_excludes_future_and_other_statuses(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $tenant = $this->tenant();

        $due = $this->order($tenant->id, [
            'full_name'    => 'Перезвон Просрочен',
            'status'       => 'Перезвонить',
            'callback_at'  => now()->subMinute(),
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Перезвон В Будущем',
            'status'       => 'Перезвонить',
            'callback_at'  => now()->addHour(),
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Позвонить С Датой',
            'status'       => 'Позвонить',
            'callback_at'  => now()->subMinute(),
        ]);
        $this->order($tenant->id, [
            'full_name'    => 'Перезвон Без Даты',
            'status'       => 'Перезвонить',
            'callback_at'  => null,
        ]);

        $ids = OrderSegment::apply(Order::withoutGlobalScopes(), OrderSegment::CALLBACK_DUE)
            ->pluck('id')
            ->all();

        $this->assertSame([$due->id], $ids);
    }
}
