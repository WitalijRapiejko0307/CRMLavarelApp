<?php

namespace Tests\Feature;

use App\Models\Income;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStockAndIncomeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Product, 2: Order}
     */
    private function seedOrder(string $deliveryType = 'belpost', string $status = 'Оформлен'): array
    {
        $tenant = Tenant::create([
            'name'                => 'Test Co ' . uniqid(),
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
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => $status,
            'delivery_type' => $deliveryType,
            'goods'         => ['Крем'],
            'quantities'    => [2],
            'prices'        => [15.5],
        ]);

        return [$tenant, $product, $order];
    }

    public function test_income_created_on_first_zavrshen(): void
    {
        [, , $order] = $this->seedOrder();

        $order->update(['status' => 'Завершен']);

        $income = Income::withoutGlobalScopes()->where('order_id', $order->id)->first();
        $this->assertNotNull($income);
        $this->assertSame(31.0, $income->amount);
        $product = Product::withoutGlobalScopes()->where('name', 'Крем')->where('tenant_id', $order->tenant_id)->first();
        $this->assertSame(2, $product->sold_count);
        $this->assertSame(31.0, $product->sold_amount);
        $this->assertSame('заказ', $income->source);
        $this->assertSame($order->id . ' ' . $order->full_name, $income->description);
    }

    public function test_no_second_income_on_repeated_zavrshen(): void
    {
        [, , $order] = $this->seedOrder();

        $order->update(['status' => 'Завершен']);
        $order->update(['status' => 'Завершен']);

        $this->assertSame(1, Income::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }

    public function test_leaving_money_status_deletes_auto_income_keeps_manual(): void
    {
        [$tenant, , $order] = $this->seedOrder();

        $order->update(['status' => 'Завершен']);

        Income::withoutGlobalScopes()->create([
            'tenant_id'   => $tenant->id,
            'amount'      => 100,
            'order_id'    => null,
            'description' => 'manual',
            'source'      => 'прочее',
            'date'        => now()->toDateString(),
        ]);

        $order->update(['status' => 'Отправлено']);

        $this->assertNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());
        $this->assertSame(1, Income::withoutGlobalScopes()->whereNull('order_id')->count());
    }

    public function test_belpost_deducts_on_otpravleno_once(): void
    {
        [, $product, $order] = $this->seedOrder();

        $order->update(['status' => 'Отправлено']);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->sold_count);
        $this->assertSame(0.0, (float) $product->fresh()->sold_amount);

        $order->update(['status' => 'В отделении']);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_personal_deducts_on_zavrshen_and_restores_on_otpravit(): void
    {
        [, $product, $order] = $this->seedOrder('personal', 'Отправить');

        $order->update(['status' => 'Завершен']);
        $this->assertSame(8, $product->fresh()->stock);

        $order->update(['status' => 'Отправить']);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_courier_deduct_on_handoff_income_on_complete(): void
    {
        [, $product, $order] = $this->seedOrder('courier', 'Отправить');

        $order->update(['status' => 'Передан курьеру']);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());

        $order->update(['status' => 'Завершен']);
        $this->assertNotNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(2, $product->fresh()->sold_count);
        $this->assertSame(31.0, (float) $product->fresh()->sold_amount);
    }

    public function test_courier_complete_to_handoff_removes_income_keeps_stock(): void
    {
        [, $product, $order] = $this->seedOrder('courier', 'Отправить');

        $order->update(['status' => 'Передан курьеру']);
        $order->update(['status' => 'Завершен']);
        $order->update(['status' => 'Передан курьеру']);

        $this->assertNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->sold_count);
        $this->assertSame(0.0, (float) $product->fresh()->sold_amount);
    }

    public function test_postal_jump_to_zavrshen_deducts_once(): void
    {
        [, $product, $order] = $this->seedOrder();

        $order->update(['status' => 'Завершен']);
        $this->assertSame(8, $product->fresh()->stock);

        $order->update(['status' => 'Завершен']);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_otpravleno_to_oformlen_restores_stock(): void
    {
        [, $product, $order] = $this->seedOrder();

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'Оформлен']);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_vozvrat_restores_stock_and_deletes_income(): void
    {
        [, $product, $order] = $this->seedOrder();

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'Завершен']);
        $this->assertNotNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'Возврат']);

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertNull(Income::withoutGlobalScopes()->where('order_id', $order->id)->first());
    }

    public function test_second_restore_does_not_double_stock(): void
    {
        [, $product, $order] = $this->seedOrder();

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'Оформлен']);
        $this->assertSame(10, $product->fresh()->stock);

        $order->update(['status' => 'Отправлено']);
        $order->update(['status' => 'Оформлен']);
        $this->assertSame(10, $product->fresh()->stock);
    }
}
