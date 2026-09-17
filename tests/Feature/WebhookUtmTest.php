<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookUtmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function createWebhookTenant(): array
    {
        $tenant = Tenant::create([
            'name'                => 'Utm Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $secret = 'utm-webhook-secret';
        TenantSetting::put($tenant->id, 'webhook_secret', $secret);

        return [$tenant, $secret];
    }

    public function test_flat_utm_fields_are_stored_and_source_stays(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $response = $this->postJson('/api/webhook/lead', [
            'name'         => 'Иванов Иван Иванович',
            'phone'        => '291234567',
            'offer'        => 'Offer A',
            'options'      => 10,
            'source'       => 'site',
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'spring',
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk()
            ->assertJson(['result' => 'success']);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('site', $order->source);
        $this->assertSame('google', $order->utm_source);
        $this->assertSame('cpc', $order->utm_medium);
        $this->assertSame('spring', $order->utm_campaign);
    }

    public function test_nested_utm_object_is_stored(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $response = $this->postJson('/api/webhook/lead', [
            'name'    => 'Иванов Иван Иванович',
            'phone'   => '291234567',
            'offer'   => 'Offer A',
            'options' => 10,
            'source'  => 'landing',
            'utm'     => [
                'source'   => 'yandex',
                'medium'   => 'organic',
                'campaign' => 'brand',
            ],
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk()
            ->assertJson(['result' => 'success']);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('landing', $order->source);
        $this->assertSame('yandex', $order->utm_source);
        $this->assertSame('organic', $order->utm_medium);
        $this->assertSame('brand', $order->utm_campaign);
    }

    public function test_flat_utm_fields_win_over_nested(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $response = $this->postJson('/api/webhook/lead', [
            'name'         => 'Иванов Иван Иванович',
            'phone'        => '291234567',
            'offer'        => 'Offer A',
            'options'      => 10,
            'source'       => 'site',
            'utm_source'   => 'flat-src',
            'utm_medium'   => 'flat-med',
            'utm_campaign' => 'flat-camp',
            'utm'          => [
                'source'   => 'nested-src',
                'medium'   => 'nested-med',
                'campaign' => 'nested-camp',
            ],
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('site', $order->source);
        $this->assertSame('flat-src', $order->utm_source);
        $this->assertSame('flat-med', $order->utm_medium);
        $this->assertSame('flat-camp', $order->utm_campaign);
    }

    public function test_empty_string_utm_is_stored_as_null(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $response = $this->postJson('/api/webhook/lead', [
            'name'         => 'Иванов Иван Иванович',
            'phone'        => '291234567',
            'offer'        => 'Offer A',
            'options'      => 10,
            'source'       => 'site',
            'utm_source'   => '',
            'utm_medium'   => '',
            'utm_campaign' => '',
        ], [
            'X-Webhook-Token' => $secret,
        ]);

        $response->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('site', $order->source);
        $this->assertNull($order->utm_source);
        $this->assertNull($order->utm_medium);
        $this->assertNull($order->utm_campaign);
    }
}
