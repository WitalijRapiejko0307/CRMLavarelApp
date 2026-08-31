<?php

namespace Tests\Unit;

use App\Models\MailBatch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OnboardingService();
    }

    private function createStoreUser(string $role = 'admin', array $tenantAttrs = []): User
    {
        $tenant = Tenant::create(array_merge([
            'name'                => 'Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ], $tenantAttrs));

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'User',
            'email'     => $role . '-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => $role,
        ]);
    }

    private function fillBelpostSettings(User $user): void
    {
        TenantSetting::put($user->tenant_id, 'auth_token_bp', 'secret-token-xyz');
        TenantSetting::put($user->tenant_id, 'elc', '123456789');
        TenantSetting::put($user->tenant_id, 'belpost_sender_email', 'shop@example.by');
        TenantSetting::put($user->tenant_id, 'shelf_life', '10');
    }

    private function addProduct(User $user): void
    {
        Product::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Товар',
            'stock'     => 1,
            'weight'    => 100,
        ]);
    }

    private function addBatch(User $user): void
    {
        MailBatch::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'batch_id'  => 'batch-1',
            'type'      => 'package',
            'status'    => MailBatch::STATUS_DRAFT,
        ]);
    }

    public function test_empty_store_admin_is_on_settings_step(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'shelf_life', '10');
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'auto-secret');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertTrue($payload['visible']);
        $this->assertTrue($payload['show_welcome']);
        $this->assertSame('settings', $payload['current_step']);
        $this->assertSame(0, $payload['completed_count']);
        $this->assertSame(3, $payload['total']);
        $this->assertTrue($payload['settings_focus']);
        $this->assertFalse($payload['belpost_ready']);
        $this->assertSame(['settings', 'products', 'belpost'], array_column($payload['steps'], 'id'));
        $this->assertStringNotContainsString('срок хранения', $payload['steps'][0]['hint']);
        $this->assertStringNotContainsString('secret-token', json_encode($payload));
        $this->assertStringNotContainsString('auto-secret', json_encode($payload));
    }

    public function test_shelf_life_default_does_not_complete_settings(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'shelf_life', '10');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertSame('settings', $payload['current_step']);
        $this->assertFalse($payload['steps'][0]['done']);
    }

    public function test_token_elc_email_advances_to_products(): void
    {
        $user = $this->createStoreUser();
        $this->fillBelpostSettings($user);

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertSame('products', $payload['current_step']);
        $this->assertTrue($payload['belpost_ready']);
        $this->assertFalse($payload['settings_focus']);
        $this->assertSame(1, $payload['completed_count']);
    }

    public function test_product_advances_to_belpost_step(): void
    {
        $user = $this->createStoreUser();
        $this->fillBelpostSettings($user);
        $this->addProduct($user);

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertSame('belpost', $payload['current_step']);
        $this->assertSame(2, $payload['completed_count']);
    }

    public function test_batch_unlocks_other_services_step(): void
    {
        $user = $this->createStoreUser();
        $this->fillBelpostSettings($user);
        $this->addProduct($user);
        $this->addBatch($user);
        TenantSetting::put($user->tenant_id, 'webhook_secret', 'auto-secret');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertSame('other', $payload['current_step']);
        $this->assertSame(4, $payload['total']);
        $this->assertTrue($payload['can_skip_optional']);
        $this->assertContains('other', array_column($payload['steps'], 'id'));
    }

    public function test_skip_optional_hides_checklist(): void
    {
        $user = $this->createStoreUser();
        $this->fillBelpostSettings($user);
        $this->addProduct($user);
        $this->addBatch($user);
        $user->onboarding_skip_optional_at = now();
        $user->save();

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertFalse($payload['visible']);
        $this->assertNull($payload['current_step']);
        $this->assertTrue($payload['optional_skipped']);
    }

    public function test_sms_completes_other_services(): void
    {
        $user = $this->createStoreUser();
        $this->fillBelpostSettings($user);
        $this->addProduct($user);
        $this->addBatch($user);
        TenantSetting::put($user->tenant_id, 'token_sms_by', 'sms-token');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertFalse($payload['visible']);
        $this->assertNull($payload['current_step']);
    }

    public function test_call_center_tenant_returns_null(): void
    {
        $user = $this->createStoreUser('admin', ['type' => Tenant::TYPE_CALL_CENTER, 'name' => 'CC']);

        $this->assertNull($this->service->forUser($user->fresh('tenant')));
    }

    public function test_manager_skips_settings_step(): void
    {
        $user = $this->createStoreUser('manager');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertTrue($payload['visible']);
        $this->assertSame('products', $payload['current_step']);
        $this->assertSame(['products', 'belpost'], array_column($payload['steps'], 'id'));
        $this->assertFalse($payload['settings_focus']);
    }

    public function test_operator_has_no_checklist(): void
    {
        $user = $this->createStoreUser('operator');

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertFalse($payload['visible']);
        $this->assertSame([], $payload['steps']);
        $this->assertSame(0, $payload['total']);
    }

    public function test_dismissed_hides_checklist(): void
    {
        $user = $this->createStoreUser();
        $user->onboarding_dismissed_at = now();
        $user->save();

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertFalse($payload['visible']);
        $this->assertTrue($payload['dismissed']);
        $this->assertSame('settings', $payload['current_step']);
        $this->assertFalse($payload['show_welcome']);
    }

    public function test_welcome_seen_hides_modal_but_keeps_checklist(): void
    {
        $user = $this->createStoreUser();
        $user->onboarding_welcome_seen_at = now();
        $user->save();

        $payload = $this->service->forUser($user->fresh('tenant'));

        $this->assertTrue($payload['visible']);
        $this->assertFalse($payload['show_welcome']);
        $this->assertSame('settings', $payload['current_step']);
    }

    public function test_login_fallback_follows_current_step(): void
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'shelf_life', '10');

        $this->assertSame(
            '/settings',
            $this->service->loginFallbackHref($user->fresh('tenant'))
        );

        $this->fillBelpostSettings($user);
        $this->assertSame(
            '/products',
            $this->service->loginFallbackHref($user->fresh('tenant'))
        );

        $this->addProduct($user);
        $this->assertSame(
            '/belpost',
            $this->service->loginFallbackHref($user->fresh('tenant'))
        );

        $this->addBatch($user);
        $this->assertSame(
            '/settings',
            $this->service->loginFallbackHref($user->fresh('tenant'))
        );

        $user->onboarding_skip_optional_at = now();
        $user->save();
        $this->assertSame(
            '/orders',
            $this->service->loginFallbackHref($user->fresh('tenant'))
        );
    }
}
