<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\SmsService;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SmsSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createActiveStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Sms Settings Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'sms-settings@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function settingValue(int $tenantId, string $key): ?string
    {
        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row === null ? null : (string) $row->value;
    }

    private function inertiaGet(User $user, string $url)
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

    public function test_admin_sees_sms_schema_keys(): void
    {
        $user = $this->createActiveStoreAdmin();

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.schema.sms.label', 'SMS.by');
        $response->assertJsonPath('props.schema.sms.keys.sms_reminder_day_1.1', 'text');
        $response->assertJsonPath('props.schema.sms.keys.sms_reminder_day_2.1', 'text');
        $response->assertJsonPath('props.schema.sms.keys.sms_tpl_shipped.1', 'textarea');
        $response->assertJsonPath('props.schema.sms.keys.sms_tpl_arrived.1', 'textarea');
        $response->assertJsonPath('props.schema.sms.keys.sms_tpl_reminder.1', 'textarea');
        $response->assertJsonPath('props.schema.sms.keys.sms_rules.1', 'custom');
        $this->assertArrayHasKey('sms_rules', $response->json('props.current'));
    }

    public function test_saving_toggles_writes_sms_rules_csv(): void
    {
        $user = $this->createActiveStoreAdmin();

        $response = $this->actingAs($user)->post('/settings', [
            'settings' => [
                'sms_rules'          => 'Отправка,В отделении,Напоминание 5 день,Напоминание 10 день',
                'sms_reminder_day_1' => '5',
                'sms_reminder_day_2' => '10',
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(
            'Отправка,В отделении,Напоминание 5 день,Напоминание 10 день',
            $this->settingValue($user->tenant_id, 'sms_rules')
        );
        $this->assertSame('5', $this->settingValue($user->tenant_id, 'sms_reminder_day_1'));
        $this->assertSame('10', $this->settingValue($user->tenant_id, 'sms_reminder_day_2'));
        $this->assertNull($this->settingValue($user->tenant_id, 'sms_on_shipped'));
    }

    public function test_saving_all_toggles_off_writes_empty_sms_rules(): void
    {
        $user = $this->createActiveStoreAdmin();
        TenantSetting::put($user->tenant_id, 'sms_rules', 'Отправка');

        $response = $this->actingAs($user)->post('/settings', [
            'settings' => [
                'sms_rules' => '',
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame('', $this->settingValue($user->tenant_id, 'sms_rules'));
    }

    public function test_saving_rewrites_reminder_tokens_from_posted_days(): void
    {
        $user = $this->createActiveStoreAdmin();

        $response = $this->actingAs($user)->post('/settings', [
            'settings' => [
                'sms_rules'          => 'Отправка,Напоминание 7 день,Напоминание 12 день',
                'sms_reminder_day_1' => '7',
                'sms_reminder_day_2' => '12',
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(
            'Отправка,Напоминание 7 день,Напоминание 12 день',
            $this->settingValue($user->tenant_id, 'sms_rules')
        );
    }

    public function test_provisioner_seeds_empty_rules_and_default_templates(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Provisioned Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app(TenantProvisioner::class)->provision($tenant, 'Provisioned Shop');

        $this->assertSame('', $this->settingValue($tenant->id, 'sms_rules'));
        $this->assertSame('5', $this->settingValue($tenant->id, 'sms_reminder_day_1'));
        $this->assertSame('10', $this->settingValue($tenant->id, 'sms_reminder_day_2'));
        $this->assertSame(SmsService::DEFAULT_TPL_SHIPPED, $this->settingValue($tenant->id, 'sms_tpl_shipped'));
        $this->assertSame(SmsService::DEFAULT_TPL_ARRIVED, $this->settingValue($tenant->id, 'sms_tpl_arrived'));
        $this->assertSame(SmsService::DEFAULT_TPL_REMINDER, $this->settingValue($tenant->id, 'sms_tpl_reminder'));
    }
}
