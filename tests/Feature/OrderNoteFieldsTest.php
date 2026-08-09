<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderNoteFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_comment_upsell_and_cross_sell(): void
    {
        $user = $this->createStoreUser();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'  => 'Иванов Иван Иванович',
            'status'     => 'Позвонить',
            'comment'    => 'Позвонить после 18:00',
            'upsell'     => 'Крем для рук',
            'cross_sell' => 'Маска',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'tenant_id'  => $user->tenant_id,
            'comment'    => 'Позвонить после 18:00',
            'upsell'     => 'Крем для рук',
            'cross_sell' => 'Маска',
            'sms_log'    => null,
        ]);
    }

    public function test_update_saves_note_fields(): void
    {
        $user  = $this->createStoreUser();
        $order = $this->createOrder($user->tenant_id, [
            'comment'    => 'Старое',
            'upsell'     => null,
            'cross_sell' => null,
        ]);

        $response = $this->actingAs($user)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'comment'    => 'Новый комментарий',
            'upsell'     => 'Апсейл',
            'cross_sell' => 'Кроссейл',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Новый комментарий', $order->comment);
        $this->assertSame('Апсейл', $order->upsell);
        $this->assertSame('Кроссейл', $order->cross_sell);
    }

    public function test_sms_log_cannot_be_set_via_update(): void
    {
        $user  = $this->createStoreUser();
        $order = $this->createOrder($user->tenant_id, [
            'sms_log' => '05.08.2026 - об отправке',
        ]);

        $this->actingAs($user)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'sms_log' => 'Пользователь пытается перезаписать',
            'comment' => 'Комментарий',
        ]);

        $order->refresh();
        $this->assertSame('05.08.2026 - об отправке', $order->sms_log);
        $this->assertSame('Комментарий', $order->comment);
    }

    public function test_call_center_can_update_note_fields(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder();

        $this->actingAs($ccUser)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'comment'    => 'CC комментарий',
            'upsell'     => 'CC апсейл',
            'cross_sell' => 'CC кроссейл',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('CC комментарий', $order->comment);
        $this->assertSame('CC апсейл', $order->upsell);
        $this->assertSame('CC кроссейл', $order->cross_sell);
    }

    public function test_call_center_cannot_update_sms_log(): void
    {
        [$ccUser, $order] = $this->createAssignedOrder([
            'sms_log' => '05.08.2026 - в отделении',
        ]);

        $this->actingAs($ccUser)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'sms_log' => 'Подмена лога',
            'comment' => 'OK',
        ]);

        $order->refresh();
        $this->assertSame('05.08.2026 - в отделении', $order->sms_log);
        $this->assertSame('OK', $order->comment);
    }

    private function createStoreUser(): User
    {
        $store = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $store->id,
            'name'      => 'Admin',
            'email'     => 'store-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createOrder(int $tenantId, array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ], $attrs));
    }

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
            'store_tenant_id'         => $store->id,
            'call_center_tenant_id'   => $cc->id,
            'status'                  => TenantConnection::STATUS_ACTIVE,
            'requested_at'            => now(),
            'approved_at'             => now(),
        ]);

        $ccUser = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Admin',
            'email'     => 'cc-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $order = Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id'             => $store->id,
            'call_center_tenant_id' => $cc->id,
            'full_name'             => 'Иванов Иван Иванович',
            'status'                => 'Позвонить',
        ], $orderAttrs));

        return [$ccUser, $order];
    }
}
