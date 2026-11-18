<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\SalesRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookCommentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: string} */
    private function createWebhookTenant(): array
    {
        $tenant = Tenant::create([
            'name'                => 'Comment Shop',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $secret = 'comment-webhook-secret';
        TenantSetting::put($tenant->id, 'webhook_secret', $secret);

        return [$tenant, $secret];
    }

    private function lead(string $secret, array $extra = [])
    {
        return $this->postJson('/api/webhook/lead', array_merge([
            'name'    => 'Иванов Иван Иванович',
            'phone'   => '291234567',
            'offer'   => 'Offer A',
            'options' => 10,
        ], $extra), ['X-Webhook-Token' => $secret]);
    }

    public function test_comment_is_stored_trimmed(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $this->lead($secret, ['comment' => "  Позвонить после 18:00  "])->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('Позвонить после 18:00', $order->comment);
    }

    public function test_missing_or_blank_comment_is_null(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $this->lead($secret)->assertOk();
        $this->lead($secret, ['comment' => '   '])->assertOk();

        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertCount(2, $orders);
        foreach ($orders as $order) {
            $this->assertNull($order->comment);
        }
    }

    public function test_too_long_comment_is_rejected(): void
    {
        [$tenant, $secret] = $this->createWebhookTenant();

        $this->lead($secret, ['comment' => str_repeat('a', 2001)])->assertStatus(422);

        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_comment_is_sent_to_sales_render_escaped(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orderMutation' => ['addOrder' => ['id' => 77]]]])]);

        $order = new Order([
            'full_name' => 'Иванов Иван',
            'phone'     => '375291234567',
            'prices'    => [10],
            'comment'   => "Позвонить \"после\" 18\nдомофон 5",
        ]);

        $id = (new SalesRenderService('token', 'company', 'project'))->sendOrder($order, 5);

        $this->assertSame('77', $id);
        Http::assertSent(function ($request) {
            $q = $request['query'];
            return str_contains($q, 'stringFields: [{ field: "comment", value: "Позвонить \"после\" 18\nдомофон 5" }]');
        });
    }

    public function test_no_string_fields_without_comment(): void
    {
        Http::fake(['*' => Http::response(['data' => ['orderMutation' => ['addOrder' => ['id' => 78]]]])]);

        $order = new Order(['full_name' => 'Иванов', 'phone' => '375291234567', 'prices' => [10]]);
        (new SalesRenderService('token', 'company', 'project'))->sendOrder($order, 5);

        Http::assertSent(fn ($request) => !str_contains($request['query'], 'stringFields'));
    }
}
