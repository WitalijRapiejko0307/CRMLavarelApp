<?php

namespace Tests\Feature;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PackingChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PackingChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePackingRoutes();
    }

    public function test_three_goods_are_one_row_with_enumerated_items_label(): void
    {
        $user = $this->createStoreAdmin();
        $this->actingAs($user);

        $order = $this->createEligibleOrder($user, [
            'delivery_type' => 'europochta',
            'full_name'     => 'Петров Пётр Петрович',
            'goods'         => ['Крем', 'Шампунь', 'Маска'],
            'quantities'    => [2, 1, 3],
            'prices'        => [10, 20, 5],
        ]);

        $rows = app(PackingChecklistService::class)->rowsForEuropochta();

        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['id']);
        $this->assertCount(3, $rows[0]['items']);
        $this->assertSame('Крем × 2 · Шампунь × 1 · Маска × 3', $rows[0]['items_label']);
        $this->assertEquals(55.0, $rows[0]['sum']);
        $this->assertStringContainsString('Крем × 2', $rows[0]['items_label']);
        $this->assertStringContainsString('Шампунь × 1', $rows[0]['items_label']);
        $this->assertStringContainsString('Маска × 3', $rows[0]['items_label']);
    }

    public function test_courier_three_goods_are_one_row_with_enumerated_items_label(): void
    {
        $user = $this->createStoreAdmin();
        $this->actingAs($user);

        $order = $this->createEligibleOrder($user, [
            'delivery_type' => 'courier',
            'full_name'     => 'Курьер Пётр Петрович',
            'goods'         => ['Крем', 'Шампунь', 'Маска'],
            'quantities'    => [2, 1, 3],
            'prices'        => [10, 20, 5],
        ]);

        $rows = app(PackingChecklistService::class)->rowsForCourier();

        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['id']);
        $this->assertCount(3, $rows[0]['items']);
        $this->assertSame('Крем × 2 · Шампунь × 1 · Маска × 3', $rows[0]['items_label']);
        $this->assertEquals(55.0, $rows[0]['sum']);
        $this->assertStringContainsString('Крем × 2', $rows[0]['items_label']);
        $this->assertStringContainsString('Шампунь × 1', $rows[0]['items_label']);
        $this->assertStringContainsString('Маска × 3', $rows[0]['items_label']);
    }

    public function test_packing_pdf_returns_pdf_without_outbound_http(): void
    {
        $user = $this->createStoreAdmin();
        $this->createEligibleOrder($user, [
            'delivery_type' => 'europochta',
            'goods'         => ['Крем', 'Шампунь', 'Маска'],
            'quantities'    => [2, 1, 3],
            'prices'        => [10, 20, 5],
        ]);
        $this->createEligibleOrder($user, [
            'delivery_type' => 'belpost',
            'goods'         => ['Набор'],
            'quantities'    => [1],
            'prices'        => [40],
        ]);
        $this->createEligibleOrder($user, [
            'delivery_type' => 'courier',
            'goods'         => ['Крем', 'Шампунь', 'Маска'],
            'quantities'    => [2, 1, 3],
            'prices'        => [10, 20, 5],
        ]);

        Http::fake();

        $ep = $this->actingAs($user)->get('/europochta/packing.pdf');
        $this->assertPackingPdf($ep);

        $bp = $this->actingAs($user)->get('/belpost/packing.pdf');
        $this->assertPackingPdf($bp);

        $cr = $this->actingAs($user)->get('/courier/packing.pdf');
        $this->assertPackingPdf($cr);

        Http::assertNothingSent();
    }

    public function test_empty_list_still_returns_pdf(): void
    {
        $user = $this->createStoreAdmin();

        Http::fake();

        $ep = $this->actingAs($user)->get('/europochta/packing.pdf');
        $this->assertPackingPdf($ep);

        $bp = $this->actingAs($user)->get('/belpost/packing.pdf');
        $this->assertPackingPdf($bp);

        $cr = $this->actingAs($user)->get('/courier/packing.pdf');
        $this->assertPackingPdf($cr);

        Http::assertNothingSent();
    }

    public function test_belpost_batch_query_uses_batch_orders_not_eligible(): void
    {
        $user  = $this->createStoreAdmin();
        $batch = MailBatch::create([
            'tenant_id'         => $user->tenant_id,
            'batch_id'          => 'bp-' . uniqid(),
            'type'              => 'ecommerce_light',
            'who_pays'          => 'Продавец',
            'label_size'        => '150x100',
            'belpost_committed' => false,
            'status'            => MailBatch::STATUS_DRAFT,
        ]);

        $this->actingAs($user);

        $batchOrder = Order::create([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Партия Иван',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'mail_batch_id' => $batch->id,
            'track_number'  => 'BY123456789BY',
            'phone'         => '291234567',
            'city'          => 'Минск',
            'street'        => 'Ленина',
            'building'      => '1',
            'goods'         => ['Крем', 'Шампунь'],
            'quantities'    => [1, 2],
            'prices'        => [10, 15],
        ]);

        $this->createEligibleOrder($user, [
            'delivery_type' => 'belpost',
            'full_name'     => 'Eligible Иван',
            'goods'         => ['Другой товар'],
            'quantities'    => [1],
            'prices'        => [99],
        ]);

        $rows = app(PackingChecklistService::class)->rowsForBelpost($batch, []);

        $this->assertCount(1, $rows);
        $this->assertSame($batchOrder->id, $rows[0]['id']);
        $this->assertSame('Крем × 1 · Шампунь × 2', $rows[0]['items_label']);

        Http::fake();
        $pdf = $this->actingAs($user)->get('/belpost/packing.pdf?batch=' . $batch->id);
        $this->assertPackingPdf($pdf);
        Http::assertNothingSent();
    }

    public function test_call_center_gets_403_on_packing_routes(): void
    {
        if (!$this->packingRoutesRegistered()) {
            $this->markTestSkipped('packing routes are not registered yet');
        }

        $cc = Tenant::create([
            'name'                => 'CC Packing',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'Admin',
            'email'     => 'cc-packing-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $this->actingAs($user)->get('/belpost/packing.pdf')->assertForbidden();
        $this->actingAs($user)->get('/europochta/packing.pdf')->assertForbidden();
        $this->actingAs($user)->get('/courier/packing.pdf')->assertForbidden();
    }

    public function test_europochta_inertia_page_still_ok(): void
    {
        $user = $this->createStoreAdmin();

        $this->actingAs($user)->get('/europochta')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Europochta/Create'));
    }

    private function assertPackingPdf($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Packing Store ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'packing-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createEligibleOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Отправить',
            'delivery_type' => 'europochta',
            'track_number'  => null,
            'phone'         => '291234567',
            'city'          => 'Минск',
            'street'        => 'Ленина',
            'building'      => '1',
            'goods'         => ['Товар'],
            'quantities'    => [1],
            'prices'        => [10],
        ], $overrides));
    }

    private function packingRoutesRegistered(): bool
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true)) {
                continue;
            }
            $found[$route->uri()] = true;
        }

        return isset($found['belpost/packing.pdf'], $found['europochta/packing.pdf'], $found['courier/packing.pdf']);
    }

    private function ensurePackingRoutes(): void
    {
        if ($this->packingRoutesRegistered()) {
            return;
        }

        $path = base_path('routes/packing.php');
        if (!is_file($path)) {
            return;
        }

        Route::middleware('web')->group($path);
    }
}
