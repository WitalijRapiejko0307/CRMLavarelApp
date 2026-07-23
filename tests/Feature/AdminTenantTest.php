<?php

namespace Tests\Feature;

use App\Models\Tenant;
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
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->subscription_status);
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
}
