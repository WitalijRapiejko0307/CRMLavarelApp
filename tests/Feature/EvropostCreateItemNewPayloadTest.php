<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\EvropostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvropostCreateItemNewPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_api_payload_has_payment_amount_without_declared_amount(): void
    {
        $tenant = Tenant::create([
            'name'                => 'EP Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        TenantSetting::put($tenant->id, 'token_ep', 'ep-token');
        TenantSetting::put($tenant->id, 'contractor_unn', '123456789');
        TenantSetting::put($tenant->id, 'warehouse_id_start', '7');

        Product::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Крем',
            'stock'     => 10,
            'weight'    => 200,
        ]);

        $order = Order::create([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Иванов Иван Иванович',
            'status'     => 'Отправить',
            'phone'      => '291234567',
            'city'       => 'Минск',
            'street'     => 'Ленина',
            'building'   => '1',
            'goods'      => ['Крем'],
            'quantities' => [1],
            'prices'     => [20],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/external/stores')) {
                return Http::response([
                    [
                        'id'     => 10,
                        'city'   => 'Минск',
                        'street' => 'Ленина',
                        'house'  => '1',
                    ],
                ], 200);
            }

            if (str_contains($request->url(), '/api/external/postal/create')) {
                return Http::response(['number' => 'EP999'], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $result = (new EvropostService())->createItemNew($order, $tenant->id, 'Покупатель');

        $this->assertTrue($result['success']);
        $this->assertSame('EP999', $result['track_number']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/api/external/postal/create')) {
                return false;
            }

            $data = $request->data();

            return array_key_exists('payment_amount', $data)
                && !array_key_exists('declared_amount', $data);
        });
    }
}
