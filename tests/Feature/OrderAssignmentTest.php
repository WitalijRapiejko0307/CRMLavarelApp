<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_assigns_call_center_when_active_connection_exists(): void
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $secret = 'test-webhook-secret-token-value-here';
        TenantSetting::put($store->id, 'webhook_secret', $secret);

        TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_ACTIVE,
            'requested_at'          => now(),
            'approved_at'           => now(),
        ]);

        $response = $this->postJson('/api/webhook/lead', [
            'name'    => 'Иванов Иван Иванович',
            'phone'   => '375291234567',
            'offer'   => 'Product',
            'options' => 10,
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk();

        $order = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($cc->id, $order->call_center_tenant_id);
    }
}
