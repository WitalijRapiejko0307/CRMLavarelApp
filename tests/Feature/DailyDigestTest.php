<?php

namespace Tests\Feature;

use App\Jobs\SendDailyDigestJob;
use App\Mail\DailyDigestMail;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\DailyDigestScheduler;
use App\Services\DailyDigestService;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_metrics_count_calendar_day_and_aggregate_products(): void
    {
        $this->setMinskNow('2026-09-17 15:30:00');
        $user = $this->createStoreAdmin();

        $this->createOrder($user, [
            'full_name'  => 'Лид сегодня',
            'status'     => 'Позвонить',
            'created_at' => Carbon::parse('2026-09-17 09:00:00', DailyDigestService::TIMEZONE),
        ]);
        $this->createOrder($user, [
            'full_name'  => 'Дубль сегодня',
            'status'     => 'Дубль',
            'created_at' => Carbon::parse('2026-09-17 10:00:00', DailyDigestService::TIMEZONE),
        ]);
        $this->createOrder($user, [
            'full_name'      => 'Исключён из воронки',
            'status'         => 'Позвонить',
            'funnel_exclude' => true,
            'created_at'     => Carbon::parse('2026-09-17 11:00:00', DailyDigestService::TIMEZONE),
        ]);
        $this->createOrder($user, [
            'full_name'  => 'Вчерашний лид',
            'status'     => 'Позвонить',
            'created_at' => Carbon::parse('2026-09-16 12:00:00', DailyDigestService::TIMEZONE),
        ]);

        $accepted = $this->createOrder($user, [
            'full_name'  => 'Принятый',
            'status'     => 'Подтвержден',
            'created_at' => Carbon::parse('2026-09-16 08:00:00', DailyDigestService::TIMEZONE),
        ]);
        OrderStatusHistory::create([
            'order_id'    => $accepted->id,
            'from_status' => 'Позвонить',
            'to_status'   => 'Подтвержден',
            'created_at'  => Carbon::parse('2026-09-17 12:00:00', DailyDigestService::TIMEZONE),
        ]);

        $sale = $this->createOrder($user, [
            'full_name'  => 'Продажа',
            'status'     => 'Завершен',
            'goods'      => ['Крем', 'Шампунь', 'Крем'],
            'quantities' => [1, 2, 3],
            'prices'     => [10, 5, 10],
            'created_at' => Carbon::parse('2026-09-10 08:00:00', DailyDigestService::TIMEZONE),
        ]);
        OrderStatusHistory::create([
            'order_id'    => $sale->id,
            'from_status' => 'Отправлено',
            'to_status'   => 'Завершен',
            'created_at'  => Carbon::parse('2026-09-17 14:00:00', DailyDigestService::TIMEZONE),
        ]);

        app()->instance('current_tenant_id', $user->tenant_id);
        $snapshot = app(DailyDigestService::class)->snapshot($user->tenant_id);

        $this->assertSame('2026-09-17', $snapshot['date']);
        $this->assertSame('17.09.2026', $snapshot['date_label']);
        $this->assertSame(1, $snapshot['leads']);
        $this->assertSame(1, $snapshot['accepted']);
        $this->assertSame(1, $snapshot['sales']);
        $this->assertCount(2, $snapshot['products']);
        $this->assertSame('Крем', $snapshot['products'][0]['name']);
        $this->assertSame(4, $snapshot['products'][0]['qty']);
        $this->assertEquals(40.0, $snapshot['products'][0]['sum']);
        $this->assertSame('Шампунь', $snapshot['products'][1]['name']);
        $this->assertSame(2, $snapshot['products'][1]['qty']);
        $this->assertEquals(10.0, $snapshot['products'][1]['sum']);

        $text = app(DailyDigestService::class)->format($snapshot);
        $this->assertStringContainsString('Сводка CRM за 17.09.2026', $text);
        $this->assertStringContainsString('Заявки: 1', $text);
        $this->assertStringContainsString('• Крем × 4 — 40 BYN', $text);
    }

    public function test_email_channel_sends_mail_without_telegram(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_email', 'owner@example.com');

        Mail::fake();
        Http::fake();

        $result = app(DailyDigestService::class)->send($user->tenant_id, true);

        $this->assertTrue($result['ok']);
        Mail::assertSent(DailyDigestMail::class, function (DailyDigestMail $mail) {
            return $mail->hasTo('owner@example.com')
                && $mail->dateLabel === '17.09.2026'
                && str_contains($mail->bodyText, 'Сводка CRM за 17.09.2026');
        });
        Http::assertNothingSent();
        $this->assertSame('2026-09-17', $this->settingValue($user->tenant_id, 'digest_last_sent_on'));
    }

    public function test_telegram_channel_sends_message_without_mail(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $user = $this->createStoreAdmin();
        $this->seedTelegram($user);

        Mail::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $result = app(DailyDigestService::class)->send($user->tenant_id, true);

        $this->assertTrue($result['ok']);
        Mail::assertNothingSent();
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->url(), '/sendMessage')
                && str_contains((string) ($request['text'] ?? ''), 'Сводка CRM за 17.09.2026');
        });
    }

    public function test_both_channels_send(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_email', 'owner@example.com');
        $this->seedTelegram($user);

        Mail::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $result = app(DailyDigestService::class)->send($user->tenant_id, true);

        $this->assertTrue($result['ok']);
        Mail::assertSent(DailyDigestMail::class);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage');
        });
    }

    public function test_disabled_channels_independently_do_not_throw(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_email', 'owner@example.com');
        $this->seedTelegram($user);

        Mail::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'          => false,
                'description' => 'bot blocked',
            ], 200),
        ]);

        $result = app(DailyDigestService::class)->send($user->tenant_id, true);

        $this->assertTrue($result['ok']);
        Mail::assertSent(DailyDigestMail::class);
        $this->assertSame('2026-09-17', $this->settingValue($user->tenant_id, 'digest_last_sent_on'));

        $other = $this->createStoreAdmin('digest-empty@example.com');
        $empty = app(DailyDigestService::class)->send($other->tenant_id, true);
        $this->assertFalse($empty['ok']);
        $this->assertNull($this->settingValue($other->tenant_id, 'digest_last_sent_on'));
    }

    public function test_scheduler_window_dispatches_only_inside_five_minutes(): void
    {
        $onTime = $this->createDueStore();
        Bus::fake();

        $this->setMinskNow('2026-09-17 20:59:00');
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);

        $this->setMinskNow('2026-09-17 21:00:00');
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertDispatched(SendDailyDigestJob::class, function (SendDailyDigestJob $job) use ($onTime) {
            return $job->tenantId === $onTime->tenant_id && $job->force === false;
        });

        Bus::fake();
        $this->setMinskNow('2026-09-17 21:04:00');
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);

        $lagged = $this->createDueStore();
        Bus::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertDispatched(SendDailyDigestJob::class, function (SendDailyDigestJob $job) use ($lagged) {
            return $job->tenantId === $lagged->tenant_id;
        });

        Bus::fake();
        $this->setMinskNow('2026-09-17 21:06:00');
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);
    }

    public function test_scheduler_is_idempotent_for_same_day(): void
    {
        $user = $this->createDueStore();
        $this->setMinskNow('2026-09-17 21:00:00');

        TenantSetting::put($user->tenant_id, 'digest_last_sent_on', '2026-09-17');
        Bus::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);

        TenantSetting::put($user->tenant_id, 'digest_last_sent_on', '');
        Mail::fake();
        Http::fake();
        $result = app(DailyDigestService::class)->send($user->tenant_id, false);
        $this->assertTrue($result['ok']);
        Mail::assertSent(DailyDigestMail::class, 1);
        $this->assertSame('2026-09-17', $this->settingValue($user->tenant_id, 'digest_last_sent_on'));

        Bus::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);
    }

    public function test_force_send_now_stamps_last_sent_and_blocks_evening_cron(): void
    {
        $this->setMinskNow('2026-09-17 10:15:00');
        $user = $this->createDueStore();
        TenantSetting::put($user->tenant_id, 'digest_enabled', '');

        Mail::fake();
        Http::fake();

        $response = $this->actingAs($user)->postJson('/settings/digest/send-now');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame('2026-09-17', $this->settingValue($user->tenant_id, 'digest_last_sent_on'));
        Mail::assertSent(DailyDigestMail::class);

        TenantSetting::put($user->tenant_id, 'digest_enabled', '1');
        $this->setMinskNow('2026-09-17 21:00:00');
        Bus::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);
    }

    public function test_enabled_without_channels_skips_and_rejects_save_and_send_now(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_enabled', '1');
        TenantSetting::put($user->tenant_id, 'digest_time', '21:00');

        Bus::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);

        TenantSetting::put($user->tenant_id, 'digest_enabled', '');

        $save = $this->from('/settings')->actingAs($user)->post('/settings', [
            'settings' => [
                'digest_enabled' => '1',
                'digest_time'    => '21:00',
                'digest_email'   => '',
            ],
        ]);
        $save->assertRedirect('/settings');
        $save->assertSessionHasErrors('settings.digest_email');
        $this->assertSame('', $this->settingValue($user->tenant_id, 'digest_enabled'));

        $sendNow = $this->actingAs($user)->postJson('/settings/digest/send-now');
        $sendNow->assertStatus(422);
        $sendNow->assertJsonPath('error_message', 'Укажите email или подключите Telegram');
    }

    public function test_call_center_tenant_is_never_due(): void
    {
        $this->setMinskNow('2026-09-17 21:00:00');
        $cc = $this->createCallCenterAdmin();
        TenantSetting::put($cc->tenant_id, 'digest_enabled', '1');
        TenantSetting::put($cc->tenant_id, 'digest_time', '21:00');
        TenantSetting::put($cc->tenant_id, 'digest_email', 'cc@example.com');

        Bus::fake();
        Mail::fake();
        app(DailyDigestScheduler::class)->dispatchDue();
        Bus::assertNotDispatched(SendDailyDigestJob::class);
        Mail::assertNothingSent();
    }

    public function test_store_schema_has_digest_group_and_cc_does_not(): void
    {
        $store = $this->createStoreAdmin();
        $storeResponse = $this->inertiaGet($store, '/settings');
        $storeResponse->assertOk();
        $storeResponse->assertJsonPath('props.schema.digest.label', 'Ежедневная сводка');
        $storeResponse->assertJsonPath('props.schema.digest.keys.digest_enabled.1', 'toggle');
        $storeResponse->assertJsonPath('props.schema.digest.keys.digest_time.1', 'text');
        $storeResponse->assertJsonPath('props.schema.digest.keys.digest_email.1', 'text');
        $this->assertArrayNotHasKey('digest_last_sent_on', $storeResponse->json('props.schema.digest.keys'));

        $cc = $this->createCallCenterAdmin();
        $ccResponse = $this->inertiaGet($cc, '/settings');
        $ccResponse->assertOk();
        $this->assertArrayNotHasKey('digest', $ccResponse->json('props.schema'));
    }

    public function test_provisioner_seeds_digest_defaults_for_store_only(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Provisioned Digest Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        app(TenantProvisioner::class)->provision($tenant, 'Provisioned Digest Shop');

        $this->assertSame('', $this->settingValue($tenant->id, 'digest_enabled'));
        $this->assertSame('21:00', $this->settingValue($tenant->id, 'digest_time'));
        $this->assertSame('', $this->settingValue($tenant->id, 'digest_email'));

        $cc = Tenant::create([
            'name'                => 'Provisioned Digest CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        app(TenantProvisioner::class)->provision($cc, 'Provisioned Digest CC');
        $this->assertNull($this->settingValue($cc->id, 'digest_enabled'));
        $this->assertNull($this->settingValue($cc->id, 'digest_time'));
    }

    public function test_store_admin_reports_still_ok(): void
    {
        $user = $this->createStoreAdmin();

        $this->actingAs($user)->get('/reports')->assertOk();
    }

    public function test_clearing_digest_email_persists_empty_value(): void
    {
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_email', 'keep@example.com');
        TenantSetting::put($user->tenant_id, 'digest_time', '21:00');

        $this->actingAs($user)->post('/settings', [
            'settings' => [
                'digest_enabled' => '',
                'digest_time'    => '20:30',
                'digest_email'   => '',
            ],
        ])->assertRedirect();

        $this->assertSame('', $this->settingValue($user->tenant_id, 'digest_email'));
        $this->assertSame('20:30', $this->settingValue($user->tenant_id, 'digest_time'));
    }

    private function setMinskNow(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, DailyDigestService::TIMEZONE));
    }

    private function createDueStore()
    {
        $user = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'digest_enabled', '1');
        TenantSetting::put($user->tenant_id, 'digest_time', '21:00');
        TenantSetting::put($user->tenant_id, 'digest_email', 'owner@example.com');

        return $user;
    }

    private function seedTelegram($user): void
    {
        TenantSetting::put($user->tenant_id, 'telegram_bot_token', 'test-bot-token');
        TenantSetting::put($user->tenant_id, 'telegram_chat_id', '12345');
    }

    private function settingValue(int $tenantId, string $key): ?string
    {
        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row === null ? null : (string) $row->value;
    }

    private function createStoreAdmin(string $email = ''): User
    {
        $tenant = Tenant::create([
            'name'                => 'Digest Store ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => $email !== '' ? $email : 'digest-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createCallCenterAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Digest CC ' . uniqid(),
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'cc-digest-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createOrder($user, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Позвонить',
            'delivery_type' => 'belpost',
            'phone'         => '291234567',
            'goods'         => ['Товар'],
            'quantities'    => [1],
            'prices'        => [10],
        ], $overrides));
    }

    private function inertiaGet($user, string $url)
    {
        $headers = [
            'X-Inertia'        => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $manifest = public_path('mix-manifest.json');
        if (is_file($manifest)) {
            $headers['X-Inertia-Version'] = md5_file($manifest);
        }

        return $this->actingAs($user)->get($url, $headers);
    }
}
