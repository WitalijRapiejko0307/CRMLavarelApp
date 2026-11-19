<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\ProductVariationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductVariationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'Var Shop ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        return $tenant;
    }

    private function product(Tenant $tenant, int $stock = 10, ?int $srItemId = null): Product
    {
        return Product::create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Сумка-шоппер',
            'stock'      => $stock,
            'weight'     => 100,
            'sr_item_id' => $srItemId,
        ]);
    }

    private function service(): ProductVariationService
    {
        return app(ProductVariationService::class);
    }

    // ── Rules ────────────────────────────────────────────────────────────────

    public function test_number_one_without_record_means_no_variation(): void
    {
        $product = $this->product($this->tenant());

        $this->assertNull($this->service()->resolve($product, 1, 'Основная'));
        $this->assertSame(0, ProductVariation::withoutGlobalScopes()->count());
    }

    public function test_unknown_number_makes_product_variational_and_moves_stock_to_variation_one(): void
    {
        $product = $this->product($this->tenant(), 10);
        $product->update(['sold_count' => 4, 'sold_amount' => 80]);

        $variation = $this->service()->resolve($product, 6, 'черный');

        $this->assertSame(6, $variation->number);
        $this->assertSame('черный', $variation->name);

        $base = $this->service()->find($product, 1);
        $this->assertNotNull($base);
        $this->assertSame(10, $base->stock);
        $this->assertSame(4, $base->sold_count);

        $product->refresh();
        $this->assertSame(10, $product->stock);
        $this->assertSame(4, $product->sold_count);
    }

    public function test_resolve_fills_empty_name_but_keeps_existing_one(): void
    {
        $product = $this->product($this->tenant());
        $this->service()->resolve($product, 2, null);
        $v = $this->service()->resolve($product, 2, 'Синий');
        $this->assertSame('Синий', $v->name);

        $v = $this->service()->resolve($product, 2, 'Другой');
        $this->assertSame('Синий', $v->name);
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    private function webhook(Tenant $tenant, array $extra = [])
    {
        TenantSetting::put($tenant->id, 'webhook_secret', 'var-secret');

        return $this->postJson('/api/webhook/lead', array_merge([
            'name'    => 'Иванов Иван Иванович',
            'phone'   => '291234567',
            'offer'   => 'Сумка-шоппер',
            'options' => 50,
        ], $extra), ['X-Webhook-Token' => 'var-secret']);
    }

    public function test_webhook_stores_variation_for_variational_product(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant);
        $this->service()->create($product, 1, 'Белый', 5);
        $this->service()->create($product, 6, 'Черный', 5);

        $this->webhook($tenant, ['variation' => ' черный '])->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame([['number' => 6, 'name' => 'Черный']], $order->variations);
    }

    public function test_webhook_without_variation_or_for_plain_product_has_no_variations(): void
    {
        $tenant = $this->tenant();
        $this->product($tenant);

        $this->webhook($tenant)->assertOk();
        $this->webhook($tenant, ['variation' => 'Основная'])->assertOk();

        foreach (Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() as $order) {
            $this->assertNull($order->variations);
        }
    }

    public function test_numeric_looking_name_is_matched_as_name_not_number(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant);
        $this->service()->create($product, 1, 'S', 5);
        $this->service()->create($product, 2, '42', 5);

        // "1" is not a name of any variation: ignored, not treated as number 1.
        $this->webhook($tenant, ['variation' => '1'])->assertOk();
        $this->webhook($tenant, ['variation' => 42])->assertOk();

        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $this->assertNull($orders[0]->variations);
        $this->assertSame([['number' => 2, 'name' => '42']], $orders[1]->variations);
    }

    public function test_webhook_matches_variation_by_name_case_insensitive(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant);
        $this->service()->create($product, 1, 'Черный', 5);
        $this->service()->create($product, 5, 'Белый', 5);

        $this->webhook($tenant, ['variation' => 'белый'])->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame([['number' => 5, 'name' => 'Белый']], $order->variations);
    }

    public function test_webhook_ignores_unknown_variation_name_without_rejecting_the_lead(): void
    {
        $tenant = $this->tenant();
        $this->product($tenant);

        $this->webhook($tenant, ['variation' => 'red'])->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertNull($order->variations);
    }

    public function test_send_order_uses_variation_and_retries_with_one_when_rejected(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant, 10, 2209);
        $order   = Order::create([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Иванов Иван Иванович',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'goods'      => [$product->name],
            'quantities' => [1],
            'prices'     => [50],
        ]);

        Http::fakeSequence()
            ->push(['errors' => [['message' => 'bad variation']]])
            ->push(['data' => ['orderMutation' => ['addOrder' => ['id' => '77']]]]);

        $id = (new \App\Services\SalesRenderService('token', '1', 'project'))->sendOrder($order, 2209, 6);

        $this->assertSame('77', $id);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('variation: 6', $sent[0][0]['query']);
        $this->assertStringContainsString('variation: 1', $sent[1][0]['query']);
    }

    public function test_send_order_without_variation_sends_one_once(): void
    {
        $tenant = $this->tenant();
        $order  = Order::create([
            'tenant_id' => $tenant->id, 'full_name' => 'Иванов Иван Иванович', 'phone' => '291234567',
            'status' => 'Позвонить', 'goods' => ['X'], 'quantities' => [1], 'prices' => [10],
        ]);

        Http::fake(['*' => Http::response(['data' => ['orderMutation' => ['addOrder' => ['id' => '5']]]])]);

        (new \App\Services\SalesRenderService('token', '1', 'project'))->sendOrder($order, 2209);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['query'], 'variation: 1'));
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    private function variationalProduct(Tenant $tenant): array
    {
        $product = $this->product($tenant, 0);
        $v1 = $this->service()->create($product, 1, 'Белый', 5);
        $v6 = $this->service()->create($product, 6, 'Черный', 3);
        $this->service()->recalcProductTotals($product);

        return [$product->refresh(), $v1, $v6];
    }

    private function shipOrder(Tenant $tenant, ?array $variations, int $qty = 2): Order
    {
        return Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'goods'         => ['Сумка-шоппер'],
            'quantities'    => [$qty],
            'prices'        => [50],
            'variations'    => $variations,
        ]);
    }

    public function test_deduction_goes_to_ordered_variation_and_product_total_is_the_sum(): void
    {
        $tenant = $this->tenant();
        [$product, $v1, $v6] = $this->variationalProduct($tenant);
        $this->assertSame(8, $product->stock);

        $order = $this->shipOrder($tenant, [['number' => 6, 'name' => 'Черный']], 2);
        $order->update(['status' => 'Отправлено']);

        $this->assertSame(1, $v6->fresh()->stock);
        $this->assertSame(5, $v1->fresh()->stock);
        $this->assertSame(6, $product->fresh()->stock);

        $order->update(['status' => 'Возврат']);

        $this->assertSame(3, $v6->fresh()->stock);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_order_without_variation_uses_variation_one_of_variational_product(): void
    {
        $tenant = $this->tenant();
        [$product, $v1, $v6] = $this->variationalProduct($tenant);

        $order = $this->shipOrder($tenant, null, 2);
        $order->update(['status' => 'Отправлено']);

        $this->assertSame(3, $v1->fresh()->stock);
        $this->assertSame(3, $v6->fresh()->stock);
        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_sales_stats_follow_variation(): void
    {
        $tenant = $this->tenant();
        [$product, $v1, $v6] = $this->variationalProduct($tenant);

        $order = $this->shipOrder($tenant, [['number' => 6, 'name' => 'Черный']], 2);
        $order->update(['status' => 'Завершен']);

        $this->assertSame(2, $v6->fresh()->sold_count);
        $this->assertSame(100.0, $v6->fresh()->sold_amount);
        $this->assertSame(0, $v1->fresh()->sold_count);
        $this->assertSame(2, $product->fresh()->sold_count);
    }

    public function test_plain_product_stock_is_unchanged_by_variation_logic(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant, 10);

        $order = $this->shipOrder($tenant, null, 2);
        $order->update(['status' => 'Отправлено']);

        $this->assertSame(8, $product->fresh()->stock);
    }

    // ── Order model ──────────────────────────────────────────────────────────

    public function test_good_label_ignores_misaligned_variations(): void
    {
        $order = new Order([
            'goods'      => ['A', 'B'],
            'variations' => [['number' => 2, 'name' => 'Красный']],
        ]);

        $this->assertSame('A', $order->goodLabel(0));

        $order->variations = [['number' => 2, 'name' => 'Красный'], null];
        $this->assertSame('A (Красный)', $order->goodLabel(0));
        $this->assertSame('B', $order->goodLabel(1));
    }

    // ── Products ─────────────────────────────────────────────────────────────

    private function loginStoreUser(Tenant $tenant)
    {
        $user = \App\Models\User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'User',
            'email'     => 'var-' . uniqid() . '@example.com',
            'password'  => \Illuminate\Support\Facades\Hash::make('password'),
            'role'      => 'admin',
        ]);

        return $this->actingAs($user);
    }

    public function test_create_product_with_variations_requires_variation_one_and_sums_stock(): void
    {
        $tenant = $this->tenant();
        $this->loginStoreUser($tenant);

        $this->postJson('/products', [
            'name' => 'Сумка', 'weight' => 100,
            'variations' => [['number' => 2, 'name' => 'Красный', 'stock' => 3]],
        ])->assertStatus(422);

        $this->postJson('/products', [
            'name' => 'Сумка', 'weight' => 100,
            'variations' => [
                ['number' => 1, 'name' => 'Белый', 'stock' => 4],
                ['number' => 2, 'name' => 'Красный', 'stock' => 3],
            ],
        ])->assertOk();

        $product = Product::withoutGlobalScopes()->where('name', 'Сумка')->first();
        $this->assertSame(7, $product->stock);
        $this->assertSame(2, ProductVariation::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_stock_delta_rejected_for_variational_product(): void
    {
        $tenant = $this->tenant();
        $this->loginStoreUser($tenant);
        [$product] = $this->variationalProduct($tenant);

        $this->putJson("/products/{$product->id}", ['stock_delta' => 5])->assertStatus(422);
    }

    public function test_first_variation_added_to_plain_product_inherits_its_stock(): void
    {
        $tenant  = $this->tenant();
        $product = $this->product($tenant, 12);
        $this->loginStoreUser($tenant);

        $this->postJson("/products/{$product->id}/variations", ['number' => 2, 'name' => 'Красный', 'stock' => 3])
            ->assertOk();

        $this->assertSame(12, $this->service()->find($product, 1)->stock);
        $this->assertSame(15, $product->fresh()->stock);
    }
}
