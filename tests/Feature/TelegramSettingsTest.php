<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_store_settings_schema_includes_telegram_group(): void
    {
        $user = $this->createStoreAdmin();

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.schema.telegram.label', 'Telegram');
        $response->assertJsonPath('props.schema.telegram.keys.telegram_bot_token.1', 'password');
        $response->assertJsonPath('props.schema.telegram.keys.telegram_chat_id.1', 'text');
    }

    public function test_call_center_schema_does_not_include_telegram(): void
    {
        $tenant = Tenant::create([
            'name'                => 'CC Telegram',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'CC Admin',
            'email'     => 'cc-telegram@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $schema = $response->json('props.schema');
        $this->assertArrayNotHasKey('telegram', $schema);
        $this->assertArrayNotHasKey('digest', $schema);
        $this->assertArrayHasKey('cc', $schema);
    }

    public function test_provisioner_seeds_empty_telegram_keys_for_store(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Provisioned Telegram Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app(TenantProvisioner::class)->provision($tenant, 'Provisioned Telegram Shop');

        $this->assertSame('', $this->settingValue($tenant->id, 'telegram_bot_token'));
        $this->assertSame('', $this->settingValue($tenant->id, 'telegram_chat_id'));
    }

    public function test_telegram_test_without_credentials_returns_422(): void
    {
        $user = $this->createStoreAdmin();

        Http::fake();

        $response = $this->actingAs($user)->postJson('/settings/telegram/test');

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'config_error');
        Http::assertNothingSent();
    }

    private function settingValue(int $tenantId, string $key): ?string
    {
        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row === null ? null : (string) $row->value;
    }

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Telegram Settings Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'telegram-settings@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
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
}
