<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\EvropostService;
use App\Support\TenantDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantDefaultCourierTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $settings = []): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'Courier Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        foreach ($settings as $k => $v) {
            TenantSetting::put($tenant->id, $k, $v);
        }

        return $tenant;
    }

    private function newOrder(Tenant $tenant, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Иванов Иван Иванович',
            'status'     => 'Позвонить',
            'phone'      => '291234567',
            'goods'      => ['Крем'],
            'quantities' => [1],
            'prices'     => [20],
        ], $overrides));
    }

    public function test_without_setting_new_orders_are_belpost(): void
    {
        $this->assertSame('belpost', $this->newOrder($this->tenant())->delivery_type);
    }

    public function test_europochta_default_applies_when_connected(): void
    {
        $tenant = $this->tenant(['default_courier' => 'europochta', 'token_ep' => 'tok']);

        $this->assertSame('europochta', $this->newOrder($tenant)->delivery_type);
    }

    public function test_europochta_default_falls_back_to_belpost_when_not_connected(): void
    {
        $tenant = $this->tenant(['default_courier' => 'europochta']);

        $this->assertSame('belpost', TenantDefaults::courier($tenant->id));
        $this->assertSame('belpost', $this->newOrder($tenant)->delivery_type);
    }

    public function test_explicit_delivery_type_is_kept_and_other_tenants_are_unaffected(): void
    {
        $tenant = $this->tenant(['default_courier' => 'europochta', 'token_ep' => 'tok']);
        $other  = $this->tenant();

        $this->assertSame('courier', $this->newOrder($tenant, ['delivery_type' => 'courier'])->delivery_type);
        $this->assertSame('belpost', $this->newOrder($other)->delivery_type);
    }

    public function test_sender_ops_from_order_overrides_settings_in_payload(): void
    {
        $tenant = $this->tenant([
            'token_ep' => 'tok', 'contractor_unn' => '123456789', 'warehouse_id_start' => '7',
        ]);
        app()->instance('current_tenant_id', $tenant->id);

        $fake = function ($request) {
            if (str_contains($request->url(), '/postal/create')) {
                return Http::response(['number' => 'EP1'], 200);
            }

            return Http::response([], 404);
        };

        $withOwn = $this->newOrder($tenant, [
            'status' => 'Отправить', 'delivery_type' => 'europochta',
            'europochta_store_id' => 10, 'europochta_store_start_id' => 55,
        ]);
        Http::fake($fake);
        (new EvropostService())->createItemNew($withOwn, $tenant->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/postal/create') && $r['store_id_start'] === 55);

        $default = $this->newOrder($tenant, [
            'status' => 'Отправить', 'delivery_type' => 'europochta', 'europochta_store_id' => 10,
        ]);
        Http::fake($fake);
        (new EvropostService())->createItemNew($default, $tenant->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/postal/create') && $r['store_id_start'] === 7);
    }
}
