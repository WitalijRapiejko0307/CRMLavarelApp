<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStockObserverTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantProduct(): array
    {
        $tenant = Tenant::create([
            'name'                => 'Stock Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Крем',
            'stock'     => 10,
            'weight'    => 100,
        ]);

        $order = Order::create([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Иванов Иван Иванович',
            'status'     => 'Оформлен',
            'goods'      => ['Крем'],
            'quantities' => [2],
            'prices'     => [10],
        ]);

        return [$tenant, $product, $order];
    }

    public function test_return_from_return_in_transit_restores_stock(): void
    {
        [, $product, $order] = $this->seedTenantProduct();

        $order->update(['status' => 'Отправлено']);
        $this->assertSame(8, $product->fresh()->stock);

        $order->update(['status' => 'Возврат в пути']);
        $this->assertSame(8, $product->fresh()->stock, 'Возврат в пути must not touch stock');

        $order->update(['status' => 'Возврат']);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_return_from_at_office_restores_stock(): void
    {
        [, $product, $order] = $this->seedTenantProduct();

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'В отделении']);
        $this->assertSame(8, $product->fresh()->stock);

        $order->update(['status' => 'Возврат']);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_tracking_event_time_is_stored_in_history(): void
    {
        [, , $order] = $this->seedTenantProduct();

        $eventAt = '2026-10-03 11:50:54';
        $order->update([
            'status'            => 'Отправлено',
            'status_changed_at' => $eventAt,
        ]);

        $history = OrderStatusHistory::where('order_id', $order->id)
            ->where('to_status', 'Отправлено')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($eventAt, $history->created_at->format('Y-m-d H:i:s'));
        $this->assertSame($eventAt, $order->fresh()->status_changed_at->format('Y-m-d H:i:s'));
    }
}
