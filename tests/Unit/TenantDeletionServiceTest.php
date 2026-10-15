<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TenantDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantDeletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TenantDeletionService();
    }

    public function test_delete_tenant_removes_tenant_data_and_preserves_other_tenant_orders(): void
    {
        Log::spy();

        $callCenter = Tenant::create([
            'name'                => 'Call Center Co',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $store = Tenant::create([
            'name'                => 'Store Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $callCenter->id,
            'name'      => 'Agent',
            'email'     => 'agent@callcenter.example',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $ownedOrder = Order::create([
            'tenant_id' => $callCenter->id,
            'full_name' => 'Owned Order',
            'status'    => 'Позвонить',
        ]);

        $linkedOrder = Order::create([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $callCenter->id,
            'full_name'             => 'Linked Order',
            'status'                => 'Позвонить',
        ]);

        $actorId = 42;
        $this->service->deleteTenant($callCenter, $actorId);

        $this->assertDatabaseMissing('tenants', ['id' => $callCenter->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('orders', ['id' => $ownedOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $linkedOrder->id]);
        $this->assertDatabaseHas('orders', [
            'id'                    => $linkedOrder->id,
            'call_center_tenant_id' => null,
        ]);
        $this->assertDatabaseHas('tenants', ['id' => $store->id]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('tenant.deleted', [
                'tenant_id'     => $callCenter->id,
                'actor_user_id' => $actorId,
                'action'        => 'tenant.deleted',
            ]);
    }

    public function test_delete_tenant_is_idempotent_when_tenant_already_removed(): void
    {
        Log::spy();

        $tenant = Tenant::create([
            'name'                => 'Gone Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $tenantId = $tenant->id;
        $tenant->delete();

        $this->service->deleteTenant($tenant);

        Log::shouldNotHaveReceived('info');
        $this->assertDatabaseMissing('tenants', ['id' => $tenantId]);
    }
}
