<?php

namespace Tests\Unit;

use App\Models\Tenant;
use Carbon\Carbon;

class TenantSubscriptionTest extends UnitTestCase
{
    private function makeTenant(array $attributes): Tenant
    {
        if (isset($attributes['trial_ends_at']) && is_string($attributes['trial_ends_at'])) {
            $attributes['trial_ends_at'] = Carbon::parse($attributes['trial_ends_at']);
        }

        if (isset($attributes['subscribed_at']) && is_string($attributes['subscribed_at'])) {
            $attributes['subscribed_at'] = Carbon::parse($attributes['subscribed_at']);
        }

        $tenant = new Tenant();
        $tenant->setRawAttributes($attributes, true);

        return $tenant;
    }

    public function test_active_tenant_is_not_read_only(): void
    {
        $tenant = $this->makeTenant([
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => '2026-07-22 00:00:00',
        ]);

        $this->assertFalse($tenant->isReadOnly());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->effectiveStatus());
        $this->assertNull($tenant->trialDaysLeft());
    }

    public function test_expired_trial_is_read_only(): void
    {
        $tenant = $this->makeTenant([
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => '2026-07-20 00:00:00',
        ]);

        $this->assertTrue($tenant->isReadOnly());
        $this->assertSame(Tenant::STATUS_EXPIRED, $tenant->effectiveStatus());
        $this->assertSame(0, $tenant->trialDaysLeft());
    }

    public function test_trial_days_left_counts_remaining_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

        $tenant = $this->makeTenant([
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => '2026-07-27 12:00:00',
        ]);

        $this->assertFalse($tenant->isReadOnly());
        $this->assertSame(5, $tenant->trialDaysLeft());

        Carbon::setTestNow();
    }
}
