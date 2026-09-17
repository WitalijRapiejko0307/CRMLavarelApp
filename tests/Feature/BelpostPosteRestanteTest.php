<?php

namespace Tests\Feature;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\BelpostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BelpostPosteRestanteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_item_poste_restante_sends_on_demand_without_house(): void
    {
        $tenant = Tenant::create([
            'name'                => 'BP Poste Restante ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        TenantSetting::put($tenant->id, 'auth_token_bp', 'fake-bp-token');

        $batch = MailBatch::create([
            'tenant_id'         => $tenant->id,
            'batch_id'          => 'bp-list-1',
            'type'              => 'ecommerce_light',
            'who_pays'          => 'Продавец',
            'label_size'        => '150x100',
            'belpost_committed' => false,
            'status'            => MailBatch::STATUS_DRAFT,
        ]);

        $order = Order::create([
            'tenant_id'          => $tenant->id,
            'full_name'          => 'Иванов Иван Иванович',
            'status'             => 'Отправить',
            'delivery_type'      => 'belpost',
            'phone'              => '375291234567',
            'city'               => 'Минск',
            'street'             => Order::POSTE_RESTANTE_STREET,
            'building'           => null,
            'poste_restante'     => true,
            'belpost_address_id' => '123',
            'goods'              => ['Крем'],
            'quantities'         => [1],
            'prices'             => [20],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/batch-mailing/list/')) {
                return Http::response([
                    'id'      => 'item-1',
                    's10code' => 'BY123456789BY',
                    'recipient_contact_widget_data' => [
                        'address' => [
                            'city'   => 'Минск',
                            'street' => '',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected ' . $request->url()], 404);
        });

        $result = (new BelpostService($tenant->id))->createItem($batch, $order);

        $this->assertTrue($result['success']);
        $this->assertSame('BY123456789BY', $result['track_number']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/api/v2/batch-mailing/list/bp-list-1/item')) {
                return false;
            }

            $data    = $request->data();
            $address = $data['recipient_contact_widget_data']['address'] ?? [];

            return ($address['type'] ?? null) === 'on_demand'
                && ($address['house'] ?? null) === ''
                && (string) ($address['id'] ?? '') === '123';
        });

        $order->refresh();
        $this->assertSame('Оформлен', $order->status);
        $this->assertSame('BY123456789BY', $order->track_number);
    }
}
