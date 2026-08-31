<?php

namespace Tests\Feature;

use App\Jobs\SyncSalesRenderJob;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSalesRenderJobTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'SR Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        TenantSetting::put($tenant->id, 'sr_enabled', '1');
        TenantSetting::put($tenant->id, 'api_token_call_centr', 'sr-token');
        TenantSetting::put($tenant->id, 'company_id_in_call_centre', '99');
        TenantSetting::put($tenant->id, 'project_id_in_call_centr', 'project-uuid');

        return $tenant;
    }

    private function makeOrder(int $tenantId, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'   => $tenantId,
            'full_name'   => 'Иванов Иван Иванович',
            'status'      => 'Позвонить',
            'phone'       => '291234567',
            'external_id' => 'sr-1',
            'goods'       => ['Старый'],
            'quantities'  => [1],
            'prices'      => [5],
        ], $overrides));
    }

    private function srPayload(string $status, array $extra = []): array
    {
        $order = array_merge([
            'id'     => 'sr-1',
            'status' => ['id' => '1', 'name' => $status],
            'data'   => [
                'phoneFields'     => [['value' => ['raw' => '+375291234567']]],
                'stringFields'    => [['value' => 'комментарий']],
                'booleanFields'   => [['field' => ['label' => 'апсейл']]],
                'humanNameFields' => [['value' => ['firstName' => 'Иван', 'lastName' => 'Петров']]],
                'addressFields'   => [],
            ],
            'cart' => [
                'items' => [
                    [
                        'quantity' => 2,
                        'pricing'  => ['unitPrice' => 15],
                        'sku'      => ['item' => ['name' => 'Новый товар']],
                    ],
                ],
            ],
        ], $extra);

        return [
            'data' => [
                'ordersFetcher' => [
                    'orders' => [$order],
                ],
            ],
        ];
    }

    public function test_non_final_status_updates_cart_and_keeps_call_status(): void
    {
        $tenant = $this->seedTenant();
        $order  = $this->makeOrder($tenant->id);

        Http::fake(function () {
            return Http::response($this->srPayload('В работе'), 200);
        });

        (new SyncSalesRenderJob($tenant->id))->handle();

        $order->refresh();
        $this->assertSame('Позвонить', $order->status);
        $this->assertSame(['Новый товар'], $order->goods);
        $this->assertSame([2], $order->quantities);
        $this->assertEquals([15.0], $order->prices);
        if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'comment')) {
            $this->assertSame('комментарий', $order->comment);
            $this->assertSame('апсейл', $order->upsell);
        }
    }

    public function test_spam_and_duplicates_become_refusal_error(): void
    {
        $tenant = $this->seedTenant();
        $spam   = $this->makeOrder($tenant->id, ['external_id' => 'sr-spam']);
        $dup    = $this->makeOrder($tenant->id, ['external_id' => 'sr-dup', 'phone' => '291234568']);

        Http::fake(function ($request) {
            $body = $request->body();
            if (str_contains($body, 'sr-spam')) {
                return Http::response($this->srPayloadForId('sr-spam', 'Спам', '+375291234567'), 200);
            }
            if (str_contains($body, 'sr-dup')) {
                return Http::response($this->srPayloadForId('sr-dup', 'Дубли', '+375291234568'), 200);
            }

            return Http::response(['data' => ['ordersFetcher' => ['orders' => []]]], 200);
        });

        (new SyncSalesRenderJob($tenant->id))->handle();

        $this->assertSame('Отказ(Ошибка)', $spam->fresh()->status);
        $this->assertSame('Отказ(Ошибка)', $dup->fresh()->status);
    }

    public function test_final_status_validation_failure_is_stored(): void
    {
        $tenant = $this->seedTenant();
        $this->makeOrder($tenant->id, ['phone' => '111111111']);

        Http::fake([
            'de.backend.salesrender.com/*' => Http::response($this->srPayload('Принят'), 200),
        ]);

        (new SyncSalesRenderJob($tenant->id))->handle();

        $raw = TenantSetting::get('sr_last_sync_failures');
        $failures = json_decode($raw, true);

        $this->assertIsArray($failures);
        $this->assertCount(1, $failures);
        $this->assertSame('VALIDATION', $failures[0]['reason']);
        $this->assertSame('Принят', $failures[0]['sr_status']);
    }

    public function test_banner_shared_and_dismissible(): void
    {
        $tenant = $this->seedTenant();
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'sr-banner-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        TenantSetting::put($tenant->id, 'sr_last_sync_failures', json_encode([
            ['order_id' => 1, 'external_id' => 'sr-1', 'sr_status' => 'Принят', 'reason' => 'VALIDATION'],
        ], JSON_UNESCAPED_UNICODE));
        TenantSetting::put($tenant->id, 'sr_last_sync_at', now()->toIso8601String());

        $middleware = app(\App\Http\Middleware\HandleInertiaRequests::class);
        $method     = new \ReflectionMethod($middleware, 'shareSrSyncFailures');
        $method->setAccessible(true);

        $shared = $method->invoke($middleware, $user);
        $this->assertNotEmpty($shared['failures'] ?? []);

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->actingAs($user)->post('/api/sr-sync/failures/dismiss')->assertStatus(204);

        $shared = $method->invoke($middleware, $user);
        $this->assertNull($shared);
    }

    private function srPayloadForId(string $id, string $status, string $phone): array
    {
        $payload = $this->srPayload($status);
        $payload['data']['ordersFetcher']['orders'][0]['id'] = $id;
        $payload['data']['ordersFetcher']['orders'][0]['data']['phoneFields'][0]['value']['raw'] = $phone;

        return $payload;
    }
}
