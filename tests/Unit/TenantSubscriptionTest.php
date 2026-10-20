<?php

namespace Tests\Unit;

use App\Models\Tenant;
use Carbon\Carbon;

class TenantSubscriptionTest extends UnitTestCase
{
    private function makeTenant(array $attributes): Tenant
    {
        foreach (['trial_ends_at', 'subscribed_at', 'subscription_ends_at', 'subscription_prepaid_at'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = Carbon::parse($attributes[$key]);
            }
        }

        $tenant = new Tenant();
        $tenant->setRawAttributes($attributes, true);

        return $tenant;
    }

    /**
     * Tenant stub that persists attribute changes in memory (no DB).
     */
    private function makePersistedTenant(array $attributes): Tenant
    {
        return new class($attributes) extends Tenant {
            public function __construct(array $attributes)
            {
                parent::__construct();
                $this->setRawAttributes(array_merge(['id' => 1], $attributes), true);
            }

            public function update(array $attributes = [], array $options = [])
            {
                $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);
                $this->syncOriginal();

                return true;
            }

            public function refresh()
            {
                return $this;
            }

            public function fresh($with = [])
            {
                $clone = clone $this;
                $clone->setRawAttributes($this->getAttributes(), true);
                $clone->syncOriginal();

                return $clone;
            }
        };
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

    public function test_activate_during_future_trial_keeps_trial_and_sets_prepaid_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $trialEnds = Carbon::parse('2026-07-24 10:00:00');

        $tenant = $this->makePersistedTenant([
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => $trialEnds,
        ]);

        $tenant->activate();
        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->subscription_status);
        $this->assertNotNull($tenant->subscription_prepaid_at);
        $this->assertTrue($tenant->subscribed_at->equalTo($trialEnds));
        $this->assertTrue($tenant->subscription_ends_at->equalTo($trialEnds->copy()->addMonth()));
        $this->assertTrue($tenant->trial_ends_at->equalTo($trialEnds));
        $this->assertFalse($tenant->isReadOnly());
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->effectiveStatus());

        Carbon::setTestNow();
    }

    public function test_prepaid_trial_promotes_to_active_after_trial_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $trialEnds = Carbon::parse('2026-07-24 10:00:00');

        $tenant = $this->makePersistedTenant([
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => $trialEnds,
        ]);
        $tenant->activate();
        $tenant->refresh();

        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));
        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->effectiveStatus());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->subscription_status);
        $this->assertFalse($tenant->isReadOnly());

        Carbon::setTestNow();
    }

    public function test_activate_after_trial_end_sets_active_period_from_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00'));

        $tenant = $this->makePersistedTenant([
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => Carbon::parse('2026-07-20 10:00:00'),
        ]);

        $tenant->activate();
        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
        $this->assertNull($tenant->trial_ends_at);
        $this->assertNotNull($tenant->subscription_prepaid_at);
        $this->assertTrue($tenant->subscribed_at->equalTo(Carbon::parse('2026-07-30 10:00:00')));
        $this->assertTrue($tenant->subscription_ends_at->equalTo(Carbon::parse('2026-08-30 10:00:00')));
        $this->assertFalse($tenant->isReadOnly());

        Carbon::setTestNow();
    }

    public function test_extend_subscription_from_future_end_adds_one_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $endsAt = Carbon::parse('2026-08-15 10:00:00');

        $tenant = $this->makePersistedTenant([
            'subscription_status'  => Tenant::STATUS_ACTIVE,
            'trial_ends_at'        => null,
            'subscribed_at'        => Carbon::parse('2026-07-01 10:00:00'),
            'subscription_ends_at' => $endsAt,
        ]);

        $tenant->extendSubscription();
        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
        $this->assertTrue($tenant->subscription_ends_at->equalTo($endsAt->copy()->addMonth()));
        $this->assertNotNull($tenant->subscription_prepaid_at);

        Carbon::setTestNow();
    }

    public function test_extend_subscription_when_end_is_past_starts_from_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $tenant = $this->makePersistedTenant([
            'subscription_status'  => Tenant::STATUS_ACTIVE,
            'trial_ends_at'        => null,
            'subscribed_at'        => Carbon::parse('2026-07-01 10:00:00'),
            'subscription_ends_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);

        $tenant->extendSubscription();
        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
        $this->assertTrue($tenant->subscription_ends_at->equalTo(Carbon::parse('2026-10-01 10:00:00')));

        Carbon::setTestNow();
    }

    public function test_active_tenant_with_past_subscription_end_is_read_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $tenant = $this->makeTenant([
            'subscription_status'  => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => '2026-08-01 10:00:00',
        ]);

        $this->assertSame(Tenant::STATUS_EXPIRED, $tenant->effectiveStatus());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
        $this->assertTrue($tenant->isReadOnly());

        Carbon::setTestNow();
    }

    public function test_active_tenant_without_subscription_end_stays_writable(): void
    {
        $tenant = $this->makeTenant([
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => '2026-07-22 00:00:00',
            'subscription_ends_at' => null,
        ]);

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->effectiveStatus());
        $this->assertFalse($tenant->isReadOnly());
        $this->assertNull($tenant->accessEndsAt());
    }
}
