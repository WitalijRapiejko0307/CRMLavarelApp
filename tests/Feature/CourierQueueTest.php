<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourierQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_store_admin_sees_only_eligible_courier_orders(): void
    {
        $user = $this->createStoreAdmin();

        $courier = $this->createOrder($user, [
            'delivery_type' => 'courier',
            'full_name'     => 'Курьер Иван',
        ]);
        $this->createOrder($user, [
            'delivery_type' => 'belpost',
            'full_name'     => 'Белпочта Иван',
        ]);
        $this->createOrder($user, [
            'delivery_type' => 'europochta',
            'full_name'     => 'Европочта Иван',
        ]);

        $this->actingAs($user)->get('/courier')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Courier/Index')
                ->has('eligibleOrders', 1)
                ->where('eligibleOrders.0.id', $courier->id)
                ->where('eligibleOrders.0.full_name', 'Курьер Иван')
            );
    }

    public function test_call_center_is_forbidden_on_courier_routes(): void
    {
        $store = $this->createStoreAdmin();
        $order = $this->createOrder($store, ['delivery_type' => 'courier']);
        $cc    = $this->createCallCenterAdmin();

        $this->actingAs($cc)->get('/courier')->assertForbidden();
        $this->actingAs($cc)->get('/courier/packing.pdf')->assertForbidden();
        $this->actingAs($cc)->get('/courier/orders/' . $order->id . '/sheet.pdf')->assertForbidden();
    }

    public function test_courier_sheet_pdf_returns_pdf_without_outbound_http(): void
    {
        $user  = $this->createStoreAdmin();
        $order = $this->createOrder($user, [
            'delivery_type' => 'courier',
            'goods'         => ['Крем', 'Шампунь'],
            'quantities'    => [2, 1],
            'prices'        => [10, 20],
            'comment'       => 'Домофон 12',
            'upsell'        => 'Маска',
            'cross_sell'    => 'Щётка',
        ]);

        Http::fake();

        $response = $this->actingAs($user)->get('/courier/orders/' . $order->id . '/sheet.pdf');

        $this->assertPdf($response);
        Http::assertNothingSent();
    }

    public function test_belpost_order_sheet_pdf_is_not_found(): void
    {
        $user  = $this->createStoreAdmin();
        $order = $this->createOrder($user, ['delivery_type' => 'belpost']);

        Http::fake();

        $this->actingAs($user)->get('/courier/orders/' . $order->id . '/sheet.pdf')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_telegram_without_settings_returns_422_and_does_not_change_order(): void
    {
        $user  = $this->createStoreAdmin();
        $order = $this->createOrder($user, ['delivery_type' => 'courier']);

        Http::fake();

        $response = $this->actingAs($user)->postJson('/courier/orders/' . $order->id . '/telegram');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error', 'config_error');

        $order->refresh();
        $this->assertSame('Отправить', $order->status);

        Http::assertNothingSent();
    }

    public function test_telegram_with_settings_sends_document_and_keeps_status(): void
    {
        $user  = $this->createStoreAdmin();
        $order = $this->createOrder($user, [
            'delivery_type' => 'courier',
            'full_name'     => 'Курьер Пётр',
        ]);
        $this->seedTelegram($user);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/courier/orders/' . $order->id . '/telegram');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('Отправить', $order->status);

        Http::assertSent(function ($request) use ($order) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->url(), '/sendDocument')
                && $request->isMultipart()
                && $request->hasFile('document', null, 'courier-' . $order->id . '.pdf');
        });
    }

    public function test_telegram_api_ok_false_returns_422_and_keeps_status(): void
    {
        $user  = $this->createStoreAdmin();
        $order = $this->createOrder($user, ['delivery_type' => 'courier']);
        $this->seedTelegram($user);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok'          => false,
                'description' => 'Bad Request: chat not found',
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/courier/orders/' . $order->id . '/telegram');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('chat not found', (string) $response->json('error_message'));

        $order->refresh();
        $this->assertSame('Отправить', $order->status);
    }

    public function test_telegram_all_sends_only_eligible_courier_orders(): void
    {
        $user = $this->createStoreAdmin();
        $first = $this->createOrder($user, [
            'delivery_type' => 'courier',
            'full_name'     => 'Курьер Один',
        ]);
        $second = $this->createOrder($user, [
            'delivery_type' => 'courier',
            'full_name'     => 'Курьер Два',
            'track_number'  => 'SHOULD-NOT-FILTER',
        ]);
        $this->createOrder($user, [
            'delivery_type' => 'belpost',
            'full_name'     => 'Белпочта',
        ]);
        $this->createOrder($user, [
            'delivery_type' => 'europochta',
            'full_name'     => 'Европочта',
        ]);
        $this->seedTelegram($user);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/courier/telegram-all');

        $response->assertOk();
        $results = $response->json('results');
        $this->assertIsArray($results);
        $this->assertArrayHasKey($first->id, $results);
        $this->assertArrayHasKey($second->id, $results);
        $this->assertCount(2, $results);
        $this->assertTrue($results[$first->id]['success']);
        $this->assertTrue($results[$second->id]['success']);

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            if (!str_contains($request->url(), '/sendDocument')) {
                return false;
            }
            $sent[] = $request->url();

            return true;
        });
        $this->assertCount(2, $sent);

        $first->refresh();
        $second->refresh();
        $this->assertSame('Отправить', $first->status);
        $this->assertSame('Отправить', $second->status);
    }

    public function test_settings_telegram_test_sends_ping_text(): void
    {
        $user = $this->createStoreAdmin();
        $this->seedTelegram($user);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/settings/telegram/test');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->url(), '/sendMessage')
                && ($request['text'] ?? null) === 'CRM: связь ок';
        });
    }

    public function test_courier_packing_pdf_returns_pdf_without_outbound_http(): void
    {
        $user = $this->createStoreAdmin();
        $this->createOrder($user, [
            'delivery_type' => 'courier',
            'goods'         => ['Крем', 'Шампунь', 'Маска'],
            'quantities'    => [2, 1, 3],
            'prices'        => [10, 20, 5],
        ]);

        Http::fake();

        $response = $this->actingAs($user)->get('/courier/packing.pdf');
        $this->assertPdf($response);
        Http::assertNothingSent();
    }

    private function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function seedTelegram(User $user): void
    {
        TenantSetting::put($user->tenant_id, 'telegram_bot_token', 'test-bot-token');
        TenantSetting::put($user->tenant_id, 'telegram_chat_id', '12345');
    }

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Courier Store ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'courier-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createCallCenterAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'CC Courier ' . uniqid(),
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'cc-courier-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Отправить',
            'delivery_type' => 'courier',
            'phone'         => '291234567',
            'city'          => 'Минск',
            'street'        => 'Ленина',
            'building'      => '1',
            'goods'         => ['Товар'],
            'quantities'    => [1],
            'prices'        => [10],
        ], $overrides));
    }
}
