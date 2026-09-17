<?php

namespace Tests\Feature;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BelpostBatchRemoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Belpost P3 Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'belpost-p3-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createDraftBatch(User $user, array $overrides = []): MailBatch
    {
        return MailBatch::create(array_merge([
            'tenant_id'         => $user->tenant_id,
            'batch_id'          => 'bp-' . uniqid(),
            'type'              => 'ecommerce_light',
            'who_pays'          => 'Продавец',
            'label_size'        => '150x100',
            'belpost_committed' => false,
            'status'            => MailBatch::STATUS_DRAFT,
        ], $overrides));
    }

    private function createBatchOrder(User $user, MailBatch $batch, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'mail_batch_id' => $batch->id,
            'track_number'  => null,
            'phone'         => '291234567',
            'city'          => 'Минск',
            'street'        => 'Ленина',
            'building'      => '1',
            'goods'         => ['Крем'],
            'quantities'    => [1],
            'prices'        => [20],
        ], $overrides));
    }

    public function test_remove_draft_order_without_track_unlinks_and_returns_to_eligible(): void
    {
        $user  = $this->createStoreAdmin();
        $batch = $this->createDraftBatch($user);
        $order = $this->createBatchOrder($user, $batch, ['status' => 'Оформлен']);

        $response = $this->actingAs($user)
            ->postJson("/belpost/batches/{$batch->id}/items/{$order->id}/remove");

        $response->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertNull($order->mail_batch_id);
        $this->assertSame('Отправить', $order->status);

        $this->actingAs($user)->get('/belpost')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Belpost/Batch')
                ->has('eligibleOrders', 1)
                ->where('eligibleOrders.0.id', $order->id)
            );
    }

    public function test_remove_draft_order_with_track_returns_422_and_keeps_batch(): void
    {
        $user  = $this->createStoreAdmin();
        $batch = $this->createDraftBatch($user);
        $order = $this->createBatchOrder($user, $batch, [
            'track_number' => 'BY123456789BY',
            'status'       => 'Оформлен',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/belpost/batches/{$batch->id}/items/{$order->id}/remove");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Заказ уже оформлен на Белпочте',
            ]);

        $order->refresh();
        $this->assertSame($batch->id, $order->mail_batch_id);
        $this->assertSame('BY123456789BY', $order->track_number);
        $this->assertSame('Оформлен', $order->status);
    }

    public function test_remove_from_committed_batch_returns_422(): void
    {
        $user  = $this->createStoreAdmin();
        $batch = $this->createDraftBatch($user, ['belpost_committed' => true, 'status' => MailBatch::STATUS_COMMITTED]);
        $order = $this->createBatchOrder($user, $batch, ['track_number' => null, 'status' => 'Оформлен']);

        $response = $this->actingAs($user)
            ->postJson("/belpost/batches/{$batch->id}/items/{$order->id}/remove");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Партия уже сформирована на Белпочте',
            ]);

        $order->refresh();
        $this->assertSame($batch->id, $order->mail_batch_id);
        $this->assertSame('Оформлен', $order->status);
    }

    public function test_eligible_orders_include_weight_and_tariff_hint(): void
    {
        $user = $this->createStoreAdmin();

        Product::create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Лёгкий крем',
            'stock'     => 10,
            'weight'    => 200,
        ]);
        Product::create([
            'tenant_id' => $user->tenant_id,
            'name'      => 'Тяжёлый набор',
            'stock'     => 10,
            'weight'    => 800,
        ]);

        $lightOrder = Order::create([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Лёгкий Заказ Тестов',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
            'track_number'  => null,
            'goods'         => ['Лёгкий крем'],
            'quantities'    => [1],
            'prices'        => [10],
        ]);
        $heavyOrder = Order::create([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Тяжёлый Заказ Тестов',
            'status'        => 'Отправить',
            'delivery_type' => 'belpost',
            'track_number'  => null,
            'goods'         => ['Тяжёлый набор'],
            'quantities'    => [1],
            'prices'        => [40],
        ]);

        $this->actingAs($user)->get('/belpost')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Belpost/Batch')
                ->has('eligibleOrders', 2)
                ->where('eligibleOrders', function ($orders) use ($lightOrder, $heavyOrder) {
                    $rows  = collect($orders);
                    $light = $rows->firstWhere('id', $lightOrder->id);
                    $heavy = $rows->firstWhere('id', $heavyOrder->id);

                    return $light
                        && (int) $light['weight'] === 200
                        && ($light['weight_hint']['label'] ?? null) === 'Лайт'
                        && ($light['weight_hint']['type'] ?? null) === 'ecommerce_light'
                        && $heavy
                        && (int) $heavy['weight'] === 800
                        && ($heavy['weight_hint']['label'] ?? null) === 'Стандарт'
                        && ($heavy['weight_hint']['type'] ?? null) === 'ecommerce_standard';
                })
            );
    }
}
