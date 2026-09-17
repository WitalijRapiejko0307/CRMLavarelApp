<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'name'                => 'Sms Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
    }

    private function createOrder(int $tenantId, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'         => $tenantId,
            'full_name'         => 'Иванов Иван Иванович',
            'status'            => 'Отправлено',
            'phone'             => '291234567',
            'track_number'      => 'BY123456',
            'goods'             => ['Крем для лица'],
            'sms_log'           => null,
            'status_changed_at' => now(),
        ], $overrides));
    }

    public function test_empty_rules_does_not_http_post(): void
    {
        $tenant = $this->createTenant();
        app()->instance('current_tenant_id', $tenant->id);
        $order = $this->createOrder($tenant->id);

        Http::fake([
            'https://app.sms.by/*' => Http::response(['sms_id' => 'should-not-send'], 200),
        ]);

        $sms = new SmsService('token', 'alpha', '');
        $sent = $sms->sendForOrder($order, 0);

        $this->assertFalse($sent);
        Http::assertNothingSent();
        $order->refresh();
        $this->assertNull($order->sms_log);
    }

    public function test_shipped_flag_uses_template_placeholders_and_writes_log(): void
    {
        $tenant = $this->createTenant();
        app()->instance('current_tenant_id', $tenant->id);
        $order = $this->createOrder($tenant->id);

        Http::fake([
            'https://app.sms.by/*' => Http::response(['sms_id' => 'sms-1'], 200),
        ]);

        $sms = new SmsService(
            'token',
            'alpha',
            'Отправка',
            'Hi {name} track {track} item {tovar}',
        );
        $sent = $sms->sendForOrder($order, 0);

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            $url = urldecode($request->url());
            return str_contains($request->url(), 'app.sms.by')
                && str_contains($url, 'Hi Иван Иванович track BY123456 item Крем для лица');
        });

        $order->refresh();
        $this->assertStringContainsString('об отправке', (string) $order->sms_log);
        $this->assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4} - об отправке/', (string) $order->sms_log);
    }

    public function test_custom_reminder_day_sends_and_day_five_does_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00'));

        $tenant = $this->createTenant();
        app()->instance('current_tenant_id', $tenant->id);

        TenantSetting::put($tenant->id, 'token_sms_by', 'token');
        TenantSetting::put($tenant->id, 'alphaname_id', 'alpha');
        TenantSetting::put($tenant->id, 'sms_rules', 'Напоминание 7 день');
        TenantSetting::put($tenant->id, 'sms_reminder_day_1', '7');
        TenantSetting::put($tenant->id, 'sms_reminder_day_2', '10');
        TenantSetting::put($tenant->id, 'sms_tpl_reminder', 'Ждём {days} дней {name} {track} {tovar}');

        Http::fake([
            'https://app.sms.by/*' => Http::response(['sms_id' => 'sms-2'], 200),
        ]);

        $sms = SmsService::forTenant($tenant->id);
        $this->assertNotNull($sms);

        $onDay7 = $this->createOrder($tenant->id, [
            'status'            => 'В отделении',
            'status_changed_at' => Carbon::parse('2026-09-09 12:00:00'),
        ]);
        $this->assertTrue($sms->sendForOrder($onDay7, 2));
        Http::assertSent(function ($request) {
            $url = urldecode($request->url());
            return str_contains($url, 'Ждём 7 дней Иван Иванович BY123456 Крем для лица');
        });
        $onDay7->refresh();
        $this->assertStringContainsString('7 день', (string) $onDay7->sms_log);

        Http::fake([
            'https://app.sms.by/*' => Http::response(['sms_id' => 'sms-should-not'], 200),
        ]);

        $onDay5 = $this->createOrder($tenant->id, [
            'status'            => 'В отделении',
            'status_changed_at' => Carbon::parse('2026-09-11 12:00:00'),
        ]);
        $this->assertFalse($sms->sendForOrder($onDay5, 2));
        Http::assertNothingSent();
        $onDay5->refresh();
        $this->assertNull($onDay5->sms_log);
    }

    public function test_for_tenant_returns_null_without_token_or_alphaname(): void
    {
        $tenant = $this->createTenant();
        TenantSetting::put($tenant->id, 'sms_rules', 'Отправка');

        $this->assertNull(SmsService::forTenant($tenant->id));

        TenantSetting::put($tenant->id, 'token_sms_by', 'token');
        $this->assertNull(SmsService::forTenant($tenant->id));

        TenantSetting::put($tenant->id, 'alphaname_id', 'alpha');
        $this->assertInstanceOf(SmsService::class, SmsService::forTenant($tenant->id));
    }
}
