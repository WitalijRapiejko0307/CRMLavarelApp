<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CallCenterWorkplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cc_show_includes_phone_return_history(): void
    {
        [$ccUser, $order, $store] = $this->createAssignedOrder([
            'phone'  => '375291111111',
            'status' => 'Позвонить',
        ]);

        $sameReturn = $this->createStoreOrder($store->id, [
            'full_name' => 'Возврат Тот Же Телефон',
            'phone'     => '80291111111',
            'status'    => 'Возврат',
        ]);
        $sameInTransit = $this->createStoreOrder($store->id, [
            'full_name' => 'Возврат В Пути Тот Же',
            'phone'     => '375291111111',
            'status'    => 'Возврат в пути',
        ]);
        $this->createStoreOrder($store->id, [
            'full_name' => 'Активный Тот Же Телефон',
            'phone'     => '375291111111',
            'status'    => 'Позвонить',
        ]);
        $this->createStoreOrder($store->id, [
            'full_name' => 'Возврат Другой Телефон',
            'phone'     => '375292222222',
            'status'    => 'Возврат',
        ]);

        $otherStore = Tenant::create([
            'name'                => 'Other Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);
        $this->createStoreOrder($otherStore->id, [
            'full_name' => 'Чужой Тенант Возврат',
            'phone'     => '375291111111',
            'status'    => 'Возврат',
        ]);

        $response = $this->actingAs($ccUser)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Show')
            ->has('phoneHistory', 2)
            ->where('phoneHistory.0.id', $sameInTransit->id)
            ->where('phoneHistory.1.id', $sameReturn->id)
            ->where('phoneHistory', fn ($history) => collect($history)->every(
                fn ($row) => $row['id'] !== $order->id
            ))
        );
    }

    public function test_phone_history_is_capped_at_ten(): void
    {
        [$ccUser, $order, $store] = $this->createAssignedOrder([
            'phone' => '375293333333',
        ]);

        foreach (range(1, 11) as $i) {
            $this->createStoreOrder($store->id, [
                'full_name' => "Возврат Номер {$i} Тестов",
                'phone'     => '375293333333',
                'status'    => 'Возврат',
            ]);
        }

        $response = $this->actingAs($ccUser)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('phoneHistory', 10)
            ->where('phoneHistory', fn ($history) => collect($history)->every(
                fn ($row) => $row['id'] !== $order->id
            ))
        );
    }

    public function test_call_script_interpolates_name_good_and_sum(): void
    {
        [$ccUser, $order, $store, $cc] = $this->createAssignedOrder([
            'full_name'  => 'Петров Петр',
            'goods'      => ['Крем'],
            'quantities' => [2],
            'prices'     => [50],
        ]);

        TenantSetting::put($cc->id, 'call_script', 'Привет {name} {tovar} {sum}');

        $response = $this->actingAs($ccUser)->get("/orders/{$order->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('callScript', 'Привет Петров Петр Крем 100.00')
        );
    }

    public function test_cc_callback_without_datetime_fails(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->from("/orders/{$order->id}")->patch("/orders/{$order->id}/status", [
            'status' => 'Перезвонить',
        ]);

        $response->assertSessionHasErrors('callback_at');
        $this->assertSame('Позвонить', $order->fresh()->status);
        $this->assertNull($order->fresh()->callback_at);
    }

    public function test_cc_callback_with_datetime_saves(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $response = $this->actingAs($ccUser)->from("/orders/{$order->id}")->patch("/orders/{$order->id}/status", [
            'status'      => 'Перезвонить',
            'callback_at' => '2026-09-16T18:00:00Z',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Перезвонить', $order->status);
        $this->assertNotNull($order->callback_at);
    }

    public function test_store_admin_can_set_callback_when_using_perezvonit(): void
    {
        $store = Tenant::create([
            'name'                => 'Store Callback',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $store->id,
            'name'      => 'Store Admin',
            'email'     => 'store-callback@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $order = $this->createStoreOrder($store->id, [
            'status' => 'Позвонить',
        ]);

        $response = $this->actingAs($user)->from("/orders/{$order->id}")->patch("/orders/{$order->id}/status", [
            'status'      => 'Перезвонить',
            'callback_at' => '2026-09-16 20:15:00',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Перезвонить', $order->status);
        $this->assertNotNull($order->callback_at);
    }

    public function test_cc_put_can_add_upsell_line(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder([
            'goods'      => ['Крем'],
            'quantities' => [1],
            'prices'     => [50],
            'upsell'     => null,
        ]);

        $response = $this->actingAs($ccUser)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'goods'      => ['Крем', 'Крем плюс'],
            'quantities' => [1, 1],
            'prices'     => [50, 15],
            'upsell'     => 'Крем плюс',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame(['Крем', 'Крем плюс'], $order->goods);
        $this->assertEquals([1, 1], $order->quantities);
        $this->assertEquals([50, 15], $order->prices);
        $this->assertSame('Крем плюс', $order->upsell);
    }

    /**
     * @return array{0: User, 1: Order, 2: Tenant, 3: Tenant}
     */
    private function createAssignedOrder(array $orderAttrs = []): array
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $cc = Tenant::create([
            'name'                => 'CC',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $cc->id,
            'status'                => TenantConnection::STATUS_ACTIVE,
            'requested_at'          => now(),
            'approved_at'           => now(),
        ]);

        $ccUser = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Admin',
            'email'     => 'cc-workplace-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $order = Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Позвонить',
        ], $orderAttrs));

        return [$ccUser, $order, $store, $cc];
    }

    private function createStoreOrder(int $tenantId, array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'full_name' => 'Клиент Магазина Тестов',
            'status'    => 'Позвонить',
        ], $attrs));
    }
}
