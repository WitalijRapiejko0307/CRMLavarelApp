<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConnectionCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_code_returns_validation_error(): void
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $store->id,
            'name'      => 'Admin',
            'email'     => 'store@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->actingAs($user)->from('/settings')->post('/connections', [
            'code' => 'INVALID1',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_regenerate_changes_connection_code(): void
    {
        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        TenantSetting::put($cc->id, 'connection_code', 'OLDCODE1');

        $user = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Admin',
            'email'     => 'cc@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $response = $this->actingAs($user)->postJson('/settings/regenerate-connection-code');

        $response->assertOk()->assertJsonStructure(['success', 'code']);

        $newCode = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $cc->id)
            ->where('key', 'connection_code')
            ->value('value');

        $this->assertNotSame('OLDCODE1', $newCode);
        $this->assertSame($response->json('code'), $newCode);
    }
}
