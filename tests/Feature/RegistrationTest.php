<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_trial_tenant_and_admin(): void
    {
        $response = $this->post('/register', [
            'company_name'          => 'New Shop',
            'name'                  => 'Owner',
            'email'                 => 'owner@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/settings');

        $this->assertDatabaseHas('tenants', [
            'name'                => 'New Shop',
            'subscription_status' => Tenant::STATUS_TRIAL,
        ]);

        $tenant = Tenant::where('name', 'New Shop')->first();
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isFuture());

        $this->assertDatabaseHas('users', [
            'email'     => 'owner@example.com',
            'tenant_id' => $tenant->id,
            'role'      => 'admin',
        ]);

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'key'       => 'shop_name',
        ]);
    }

    public function test_duplicate_email_returns_validation_error(): void
    {
        User::create([
            'tenant_id' => Tenant::create([
                'name'                => 'Existing',
                'created_at'          => now(),
                'subscription_status' => Tenant::STATUS_ACTIVE,
                'subscribed_at'       => now(),
            ])->id,
            'name'     => 'Existing',
            'email'    => 'dup@example.com',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        $response = $this->from('/register')->post('/register', [
            'company_name'          => 'Another',
            'name'                  => 'Another',
            'email'                 => 'dup@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
