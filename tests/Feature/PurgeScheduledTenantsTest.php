<?php

namespace Tests\Feature;

use App\Mail\TenantDeletionReminderMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PurgeScheduledTenantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_scheduled_deletes_past_due_tenant_and_keeps_future_scheduled(): void
    {
        Mail::fake();

        $dueTenant = Tenant::create([
            'name'                   => 'Due Co',
            'created_at'             => now(),
            'subscription_status'    => Tenant::STATUS_ACTIVE,
            'subscribed_at'          => now(),
            'deletion_scheduled_for' => now()->subDay(),
        ]);

        $futureTenant = Tenant::create([
            'name'                   => 'Future Co',
            'created_at'             => now(),
            'subscription_status'    => Tenant::STATUS_ACTIVE,
            'subscribed_at'          => now(),
            'deletion_scheduled_for' => now()->addDays(10),
        ]);

        $this->artisan('tenants:purge-scheduled')->assertSuccessful();

        $this->assertDatabaseMissing('tenants', ['id' => $dueTenant->id]);
        $this->assertDatabaseHas('tenants', ['id' => $futureTenant->id]);
    }

    public function test_purge_scheduled_sends_reminder_mail_seven_days_before_deletion(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'name'                   => 'Reminder Co',
            'created_at'             => now(),
            'subscription_status'    => Tenant::STATUS_ACTIVE,
            'subscribed_at'          => now(),
            'deletion_scheduled_for' => now()->addDays(7),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'admin-reminder@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('tenants:purge-scheduled')->assertSuccessful();

        Mail::assertSent(TenantDeletionReminderMail::class, function (TenantDeletionReminderMail $mail) use ($tenant): bool {
            return $mail->tenant->id === $tenant->id
                && $mail->hasTo('admin-reminder@example.com');
        });
    }
}
