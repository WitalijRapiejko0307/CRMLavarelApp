<?php

namespace Tests\Feature;

use App\Models\MailBatch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createStoreUser(string $role = 'admin', array $tenantAttrs = []): User
    {
        $tenant = Tenant::create(array_merge([
            'name'                => 'Onboard Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ], $tenantAttrs));

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Owner',
            'email'     => $role . '-onboard-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function inertiaGet(User $user, string $url)
    {
        return $this->actingAs($user)->get($url, [
            'X-Inertia'        => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    public function test_settings_page_shares_onboarding_and_elc_label(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'auth_token_bp', 'secret-token-xyz');

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.onboarding.visible', true);
        $response->assertJsonPath('props.onboarding.current_step', 'settings');
        $response->assertJsonPath('props.schema.belpost.keys.elc.0', 'ЭЛС (электронный лицевой счёт)');
        $this->assertStringNotContainsString('secret-token-xyz', $response->getContent());
    }

    public function test_operator_does_not_see_checklist(): void
    {
        $user = $this->createStoreUser('operator');

        $response = $this->inertiaGet($user, '/orders');

        $response->assertOk();
        $response->assertJsonPath('props.onboarding.visible', false);
        $this->assertSame([], $response->json('props.onboarding.steps'));
        $this->assertFalse($response->json('props.onboarding.show_welcome'));
    }

    public function test_dismiss_and_restore(): void
    {
        $user = $this->createStoreUser();

        $this->actingAs($user)->post('/onboarding/dismiss')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->onboarding_dismissed_at);

        $response = $this->inertiaGet($user, '/settings');
        $response->assertJsonPath('props.onboarding.visible', false);
        $response->assertJsonPath('props.onboarding.dismissed', true);

        $this->actingAs($user)->post('/onboarding/restore')->assertRedirect();
        $user->refresh();
        $this->assertNull($user->onboarding_dismissed_at);

        $response = $this->inertiaGet($user, '/settings');
        $response->assertJsonPath('props.onboarding.visible', true);
    }

    public function test_skip_optional_hides_checklist(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'auth_token_bp', 'token');
        TenantSetting::put($user->tenant_id, 'elc', 'elc-1');
        TenantSetting::put($user->tenant_id, 'belpost_sender_email', 'a@b.by');
        Product::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Товар',
            'stock'     => 1,
            'weight'    => 50,
        ]);
        MailBatch::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'batch_id'  => 'b1',
            'type'      => 'package',
            'status'    => MailBatch::STATUS_DRAFT,
        ]);

        $before = $this->inertiaGet($user, '/settings');
        $before->assertJsonPath('props.onboarding.current_step', 'other');
        $before->assertJsonPath('props.onboarding.can_skip_optional', true);

        $this->actingAs($user)->post('/onboarding/skip-optional')->assertRedirect();

        $after = $this->inertiaGet($user, '/settings');
        $after->assertJsonPath('props.onboarding.visible', false);
        $after->assertJsonPath('props.onboarding.optional_skipped', true);
    }

    public function test_manager_cannot_skip_optional(): void
    {
        $user = $this->createStoreUser('manager');

        $this->actingAs($user)->post('/onboarding/skip-optional')->assertForbidden();
    }

    public function test_expired_trial_can_dismiss_onboarding(): void
    {
        $user = $this->createStoreUser('admin', [
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
            'subscribed_at'       => null,
        ]);

        $this->actingAs($user)->post('/onboarding/dismiss')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->onboarding_dismissed_at);
    }

    public function test_call_center_has_no_onboarding_payload(): void
    {
        $user = $this->createStoreUser('admin', [
            'type' => Tenant::TYPE_CALL_CENTER,
            'name' => 'CC Co',
        ]);

        $response = $this->inertiaGet($user, '/orders');

        $response->assertOk();
        $this->assertNull($response->json('props.onboarding'));
    }

    public function test_welcome_seen_sets_flag_and_hides_modal(): void
    {
        $user = $this->createStoreUser();

        $before = $this->inertiaGet($user, '/settings');
        $before->assertJsonPath('props.onboarding.show_welcome', true);
        $before->assertJsonPath('props.onboarding.visible', true);

        $this->actingAs($user)->post('/onboarding/welcome-seen')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->onboarding_welcome_seen_at);

        $after = $this->inertiaGet($user, '/settings');
        $after->assertJsonPath('props.onboarding.show_welcome', false);
        $after->assertJsonPath('props.onboarding.visible', true);
    }

    public function test_login_redirects_new_admin_to_settings(): void
    {
        $user = $this->createStoreUser();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertRedirect('/settings');
    }

    public function test_login_redirects_to_products_after_belpost_settings(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'auth_token_bp', 'token');
        TenantSetting::put($user->tenant_id, 'elc', 'elc-1');
        TenantSetting::put($user->tenant_id, 'belpost_sender_email', 'a@b.by');

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertRedirect('/products');
    }

    public function test_login_redirects_to_belpost_after_product(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'auth_token_bp', 'token');
        TenantSetting::put($user->tenant_id, 'elc', 'elc-1');
        TenantSetting::put($user->tenant_id, 'belpost_sender_email', 'a@b.by');
        Product::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Товар',
            'stock'     => 1,
            'weight'    => 50,
        ]);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertRedirect('/belpost');
    }

    public function test_settings_schema_has_webhook_url_and_site_leads_label(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'unique-webhook-secret-xyz');

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.schema.system.label', 'Заявки с сайта');
        $this->assertStringContainsString('/api/webhook/lead', (string) $response->json('props.webhook_url'));
        $this->assertStringNotContainsString('unique-webhook-secret-xyz', $response->getContent());
        $hint = $response->json('props.schema.system.keys.webhook_secret.3');
        $this->assertIsString($hint);
        $this->assertStringNotContainsString('/api/webhook/lead', $hint);
    }

    public function test_admin_can_reveal_webhook_secret(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'reveal-me-now');

        $response = $this->actingAs($user)->postJson('/settings/reveal-webhook-secret');

        $response->assertOk();
        $response->assertJson(['success' => true, 'secret' => 'reveal-me-now']);
    }

    public function test_manager_cannot_reveal_webhook_secret(): void
    {
        $user = $this->createStoreUser('manager');
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'hidden-from-manager');

        $this->actingAs($user)->postJson('/settings/reveal-webhook-secret')->assertForbidden();
        $this->actingAs($user)->post('/settings/generate-webhook-secret')->assertForbidden();
    }

    public function test_expired_trial_can_reveal_secret_and_mark_welcome_seen(): void
    {
        $user = $this->createStoreUser('admin', [
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
            'subscribed_at'       => null,
        ]);
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'trial-secret');

        $this->actingAs($user)->postJson('/settings/reveal-webhook-secret')
            ->assertOk()
            ->assertJson(['success' => true, 'secret' => 'trial-secret']);

        $this->actingAs($user)->post('/onboarding/welcome-seen')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->onboarding_welcome_seen_at);
    }
}
