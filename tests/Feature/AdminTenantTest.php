<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::create([
            'tenant_id' => null,
            'name'      => 'Super',
            'email'     => 'super@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'super_admin',
        ]);
    }

    protected function tenantAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Tenant Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_super_admin_sets_access_until_on_trial_and_active(): void
    {
        $trial = Tenant::create([
            'name'                => 'Trial Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays(2),
        ]);
        $active = Tenant::create([
            'name'                  => 'Active Co',
            'created_at'            => now(),
            'subscription_status'   => Tenant::STATUS_ACTIVE,
            'subscription_ends_at'  => now()->addDays(10),
            'subscribed_at'         => now(),
        ]);
        $admin = $this->superAdmin();
        $trialUntil = now()->addDays(5)->format('Y-m-d H:i:s');
        $activeUntil = now()->addDays(20)->format('Y-m-d H:i:s');

        $this->actingAs($admin)
            ->patch("/admin/tenants/{$trial->id}", [
                'subscription_status' => Tenant::STATUS_TRIAL,
                'access_until'        => $trialUntil,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch("/admin/tenants/{$active->id}", [
                'subscription_status' => Tenant::STATUS_ACTIVE,
                'access_until'        => $activeUntil,
            ])
            ->assertRedirect();

        $trial->refresh();
        $active->refresh();
        $this->assertSame($trialUntil, $trial->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertNull($trial->subscription_ends_at);
        $this->assertSame($activeUntil, $active->subscription_ends_at->format('Y-m-d H:i:s'));
    }

    public function test_expired_status_update_does_not_change_access_dates(): void
    {
        $ends = now()->addDays(4);
        $tenant = Tenant::create([
            'name'                  => 'Ending Co',
            'created_at'            => now(),
            'subscription_status'   => Tenant::STATUS_ACTIVE,
            'subscription_ends_at'  => $ends,
            'subscribed_at'         => now(),
        ]);

        $this->actingAs($this->superAdmin())
            ->patch("/admin/tenants/{$tenant->id}", [
                'subscription_status' => Tenant::STATUS_EXPIRED,
                'access_until'        => now()->addYear()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_EXPIRED, $tenant->subscription_status);
        $this->assertTrue($tenant->subscription_ends_at->diffInSeconds($ends) < 2);
    }

    public function test_super_admin_can_view_tenants_panel(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin/tenants');

        $response->assertOk();
    }

    public function test_tenant_admin_cannot_access_admin_panel(): void
    {
        $response = $this->actingAs($this->tenantAdmin())->get('/admin/tenants');

        $response->assertForbidden();
    }

    public function test_super_admin_can_activate_tenant(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Trial Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->post("/admin/tenants/{$tenant->id}/activate");

        $response->assertRedirect();

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->subscription_status);
        $this->assertTrue($tenant->subscription_ends_at->greaterThan($tenant->trial_ends_at));
        $expectedEnd = $tenant->trial_ends_at->copy()->addMonth();
        $this->assertTrue($tenant->subscription_ends_at->diffInSeconds($expectedEnd) < 2);
    }

    public function test_super_admin_can_extend_subscription(): void
    {
        $endsAt = now()->addDays(10);

        $tenant = Tenant::create([
            'name'                  => 'Active Co',
            'created_at'            => now(),
            'subscription_status'   => Tenant::STATUS_ACTIVE,
            'subscribed_at'         => now()->subMonth(),
            'subscription_ends_at'  => $endsAt,
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->post("/admin/tenants/{$tenant->id}/extend-subscription");

        $response->assertRedirect();

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
        $expectedEnd = $endsAt->copy()->addMonth();
        $this->assertTrue($tenant->subscription_ends_at->diffInSeconds($expectedEnd) < 2);
    }

    public function test_tenant_admin_cannot_extend_subscription(): void
    {
        $tenant = Tenant::create([
            'name'                  => 'Active Co',
            'created_at'            => now(),
            'subscription_status'   => Tenant::STATUS_ACTIVE,
            'subscribed_at'         => now(),
            'subscription_ends_at'  => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->tenantAdmin())
            ->post("/admin/tenants/{$tenant->id}/extend-subscription");

        $response->assertForbidden();
    }

    public function test_super_admin_can_extend_trial(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Trial Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->addDay(),
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->post("/admin/tenants/{$tenant->id}/extend-trial", ['days' => 14]);

        $response->assertRedirect();

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->subscription_status);
        $this->assertTrue($tenant->trial_ends_at->greaterThan(now()->addDays(10)));
    }

    public function test_tenants_index_lists_admin_email_per_tenant(): void
    {
        $tenantA = Tenant::create([
            'name'                => 'Alpha Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        $tenantB = Tenant::create([
            'name'                => 'Beta Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        User::create([
            'tenant_id' => $tenantA->id,
            'name'      => 'Admin A',
            'email'     => 'alpha-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
        User::create([
            'tenant_id' => $tenantB->id,
            'name'      => 'Admin B',
            'email'     => 'beta-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->actingAs($this->superAdmin())->get('/admin/tenants');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Tenants/Index')
                ->has('tenants', 2)
                ->where('tenants', function ($tenants) {
                    $emails = collect($tenants)->pluck('admin_email')->sort()->values()->all();

                    return $emails === ['alpha-admin@example.com', 'beta-admin@example.com'];
                })
            );
    }

    public function test_disabling_leads_blocks_webhook_order_creation(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Leads Off Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
            'leads_enabled'       => true,
        ]);

        $secret = 'leads-off-secret';

        TenantSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key'       => 'webhook_secret',
            'value'     => $secret,
        ]);

        $this->actingAs($this->superAdmin())
            ->post("/admin/tenants/{$tenant->id}/leads-enabled", ['enabled' => false])
            ->assertRedirect();

        $response = $this->postJson('/api/webhook/lead', [
            'name'  => 'Иванов Иван',
            'phone' => '291234567',
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertForbidden()
            ->assertJson([
                'result'  => 'error',
                'message' => 'Leads are not accepted',
            ]);

        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_archived_tenant_user_cannot_log_in(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Archived Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
            'archived_at'         => now(),
        ]);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'archived-admin@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->post('/login', [
            'email'    => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
