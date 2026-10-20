<?php

namespace Tests\Feature;

use App\Mail\SubscriptionEndingMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionEndingReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Europe/Minsk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_reminder_when_trial_ends_in_five_days(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                => 'Trial Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays(5),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'trial-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:subscription-ending-reminder')->assertSuccessful();

        Mail::assertSent(SubscriptionEndingMail::class, function (SubscriptionEndingMail $mail) use ($tenant): bool {
            return $mail->tenant->id === $tenant->id
                && $mail->hasTo('trial-admin@example.com');
        });
    }

    public function test_does_not_send_when_trial_ends_in_four_days(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                => 'Soon Trial Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays(4),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'soon-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:subscription-ending-reminder')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sends_reminder_when_active_subscription_ends_in_five_days(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                   => 'Active Co',
            'created_at'             => now(),
            'subscription_status'    => Tenant::STATUS_ACTIVE,
            'subscribed_at'          => now(),
            'subscription_ends_at'   => now()->addDays(5),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'active-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:subscription-ending-reminder')->assertSuccessful();

        Mail::assertSent(SubscriptionEndingMail::class, function (SubscriptionEndingMail $mail) use ($tenant): bool {
            return $mail->tenant->id === $tenant->id
                && $mail->hasTo('active-admin@example.com');
        });
    }

    public function test_does_not_send_when_active_subscription_has_no_end_date(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                   => 'Unlimited Co',
            'created_at'             => now(),
            'subscription_status'    => Tenant::STATUS_ACTIVE,
            'subscribed_at'          => now(),
            'subscription_ends_at'   => null,
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'unlimited-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:subscription-ending-reminder')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sends_to_all_admins_but_not_manager(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                => 'Multi Admin Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays(5),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'First Admin',
            'email'     => 'first-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Manager',
            'email'     => 'manager@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Second Admin',
            'email'     => 'second-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:subscription-ending-reminder')->assertSuccessful();

        Mail::assertSent(SubscriptionEndingMail::class, 2);
        Mail::assertSent(SubscriptionEndingMail::class, fn (SubscriptionEndingMail $mail) => $mail->hasTo('first-admin@example.com'));
        Mail::assertSent(SubscriptionEndingMail::class, fn (SubscriptionEndingMail $mail) => $mail->hasTo('second-admin@example.com'));
        Mail::assertNotSent(SubscriptionEndingMail::class, fn (SubscriptionEndingMail $mail) => $mail->hasTo('manager@example.com'));
    }
}
