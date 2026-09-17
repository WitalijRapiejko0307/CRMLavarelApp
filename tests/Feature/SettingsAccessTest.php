<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createActiveTenantUser(string $role = 'admin'): User
    {
        $tenant = Tenant::create([
            'name'                => 'Settings Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'User',
            'email'     => "settings-{$role}@example.com",
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function seedTenantSettings(User $user): void
    {
        TenantSetting::put($user->tenant_id, 'shop_name', 'Test Shop');
    }

    private function getSettingsPage(User $user)
    {
        $headers = [
            'X-Inertia'         => 'true',
            'X-Requested-With'  => 'XMLHttpRequest',
        ];
        $manifest = public_path('mix-manifest.json');
        if (is_file($manifest)) {
            $headers['X-Inertia-Version'] = md5_file($manifest);
        }

        return $this->actingAs($user)->get('/settings', $headers);
    }

    public function test_operator_sees_only_theme(): void
    {
        $user = $this->createActiveTenantUser('operator');
        $this->seedTenantSettings($user);

        $response = $this->getSettingsPage($user);

        $response->assertOk();
        $response->assertJsonPath('props.canViewSettings', false);
        $response->assertJsonPath('props.canEditSettings', false);
        $response->assertJsonPath('props.schema', []);
    }

    public function test_manager_sees_read_only_settings(): void
    {
        $user = $this->createActiveTenantUser('manager');
        $this->seedTenantSettings($user);

        $response = $this->getSettingsPage($user);

        $response->assertOk();
        $response->assertJsonPath('props.canViewSettings', true);
        $response->assertJsonPath('props.canEditSettings', false);
        $this->assertNotEmpty($response->json('props.schema'));
    }

    public function test_admin_sees_editable_settings(): void
    {
        $user = $this->createActiveTenantUser('admin');
        $this->seedTenantSettings($user);

        $response = $this->getSettingsPage($user);

        $response->assertOk();
        $response->assertJsonPath('props.canViewSettings', true);
        $response->assertJsonPath('props.canEditSettings', true);
        $this->assertNotEmpty($response->json('props.schema'));
    }

    public function test_manager_cannot_save_settings(): void
    {
        $user = $this->createActiveTenantUser('manager');

        $response = $this->actingAs($user)->post('/settings', [
            'settings' => ['shop_name' => 'Hacked'],
        ]);

        $response->assertForbidden();
    }

    public function test_manager_cannot_generate_webhook_secret(): void
    {
        $user = $this->createActiveTenantUser('manager');

        $response = $this->actingAs($user)->post('/settings/generate-webhook-secret');

        $response->assertForbidden();
    }

    public function test_admin_can_save_settings(): void
    {
        $user = $this->createActiveTenantUser('admin');

        $response = $this->actingAs($user)->post('/settings', [
            'settings' => ['shop_name' => 'Updated Shop'],
        ]);

        $response->assertRedirect();
        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('key', 'shop_name')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('Updated Shop', $row->value);
    }

    public function test_all_roles_can_change_theme(): void
    {
        foreach (['operator', 'manager', 'admin'] as $role) {
            $user = $this->createActiveTenantUser($role);

            $response = $this->actingAs($user)->patch('/settings/theme', [
                'theme' => 'dark',
            ]);

            $response->assertOk();
            $response->assertJson(['theme' => 'dark']);

            $user->refresh();
            $this->assertSame('dark', $user->theme);
        }
    }

    public function test_call_center_schema_includes_call_script_not_store_groups(): void
    {
        $tenant = Tenant::create([
            'name'                => 'CC Settings',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'CC Admin',
            'email'     => 'cc-settings@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->getSettingsPage($user);

        $response->assertOk();
        $schema = $response->json('props.schema');
        $this->assertArrayHasKey('shop', $schema);
        $this->assertArrayHasKey('cc', $schema);
        $this->assertArrayNotHasKey('sms', $schema);
        $this->assertArrayNotHasKey('belpost', $schema);
        $this->assertSame('textarea', $schema['cc']['keys']['call_script'][1]);
        $this->assertArrayHasKey('cc_round_robin', $schema['cc']['keys']);
        $this->assertSame('toggle', $schema['cc']['keys']['cc_round_robin'][1]);
    }

    public function test_store_schema_does_not_include_call_script(): void
    {
        $user = $this->createActiveTenantUser('admin');
        $this->seedTenantSettings($user);

        $response = $this->getSettingsPage($user);

        $response->assertOk();
        $schema = $response->json('props.schema');
        $this->assertArrayNotHasKey('cc', $schema);
        $this->assertArrayHasKey('sms', $schema);
    }
}
