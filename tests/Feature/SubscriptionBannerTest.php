<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SubscriptionBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createTrialAdmin(int $daysUntilTrialEnd): User
    {
        $tenant = Tenant::create([
            'name'                => 'Trial Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays($daysUntilTrialEnd),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'trial-banner-' . uniqid() . '@example.com',
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

    public function test_inertia_shares_access_end_when_trial_ends_in_two_days(): void
    {
        $user = $this->createTrialAdmin(2);
        TenantSetting::put($user->tenant_id, 'shop_name', 'Trial Shop');

        $tenant = $user->tenant->fresh();
        $expectedIso = $tenant->accessEndsAt()?->toIso8601String();

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.subscription.accessEndsAt', $expectedIso);
        $response->assertJsonPath('props.subscription.accessDaysLeft', 2);
        $response->assertJsonPath('props.subscription.bannerDismissed', false);
    }

    public function test_dismiss_banner_stores_timestamp_equal_to_access_end(): void
    {
        $user = $this->createTrialAdmin(2);
        $tenant = $user->tenant->fresh();
        $expectedEnd = $tenant->accessEndsAt();

        $this->actingAs($user)
            ->post('/subscription/dismiss-banner')
            ->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->subscription_banner_dismissed_for);
        $this->assertTrue($user->subscription_banner_dismissed_for->equalTo($expectedEnd));
    }

    public function test_after_dismiss_shared_banner_dismissed_is_true(): void
    {
        $user = $this->createTrialAdmin(2);

        $this->actingAs($user)->post('/subscription/dismiss-banner');

        $response = $this->inertiaGet($user, '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.subscription.bannerDismissed', true);
    }

    public function test_banner_dismissed_is_false_when_trial_end_changes(): void
    {
        $user = $this->createTrialAdmin(2);

        $this->actingAs($user)->post('/subscription/dismiss-banner');

        $tenant = $user->tenant->fresh();
        $tenant->update(['trial_ends_at' => now()->addDays(5)]);

        $response = $this->inertiaGet($user->fresh(), '/settings');

        $response->assertOk();
        $response->assertJsonPath('props.subscription.bannerDismissed', false);
    }
}
