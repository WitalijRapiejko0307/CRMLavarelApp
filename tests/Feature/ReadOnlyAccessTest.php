<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReadOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function createExpiredTenantUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Expired Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'expired@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_expired_tenant_can_view_orders(): void
    {
        $user = $this->createExpiredTenantUser();

        $response = $this->actingAs($user)->get('/orders');

        $response->assertOk();
    }

    public function test_expired_tenant_cannot_create_orders(): void
    {
        $user = $this->createExpiredTenantUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ]);

        $response->assertForbidden();
    }
}
