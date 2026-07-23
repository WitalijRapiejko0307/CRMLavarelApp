<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_tenant_webhook_creates_order_without_salesrender(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Expired Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_TRIAL,
            'trial_ends_at'       => now()->subDay(),
        ]);

        $secret = 'test-webhook-secret';

        TenantSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key'       => 'webhook_secret',
            'value'     => $secret,
        ]);

        TenantSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key'       => 'sr_enabled',
            'value'     => '1',
        ]);

        TenantSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key'       => 'api_token_call_centr',
            'value'     => 'token',
        ]);

        TenantSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key'       => 'company_id_in_call_centre',
            'value'     => '123',
        ]);

        Product::withoutGlobalScopes()->create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Offer A',
            'stock'      => 10,
            'weight'     => 0.5,
            'sr_item_id' => 'sr-1',
        ]);

        $response = $this->postJson('/api/webhook/lead', [
            'name'    => 'Иванов Иван Иванович',
            'phone'   => '291234567',
            'offer'   => 'Offer A',
            'options' => 10,
            'source'  => 'site',
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk()
            ->assertJson(['result' => 'success']);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertNull($order->external_id);
    }
}
