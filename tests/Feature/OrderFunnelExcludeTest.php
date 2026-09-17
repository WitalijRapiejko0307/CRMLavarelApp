<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderFunnelExcludeTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveTenantUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Funnel Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'funnel@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createOrder(int $tenantId, array $overrides = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenantId,
            'full_name' => 'Иванов Иван Иванович',
            'status'    => 'Позвонить',
        ], $overrides));
    }

    public function test_dubl_without_extra_fields_sets_funnel_exclude(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status' => 'Дубль',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Дубль', $order->status);
        $this->assertTrue($order->funnel_exclude);
    }

    public function test_dubl_with_funnel_exclude_false_stays_false(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status'         => 'Дубль',
            'funnel_exclude' => 0,
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Дубль', $order->status);
        $this->assertFalse($order->funnel_exclude);
    }

    public function test_dubl_with_test_reason_sets_exclude_and_appends_comment(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id, ['comment' => 'Уже есть']);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status'        => 'Дубль',
            'funnel_reason' => 'test',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertTrue($order->funnel_exclude);
        $this->assertStringContainsString('тест', $order->comment);
        $this->assertStringContainsString('Уже есть', $order->comment);
    }

    public function test_dubl_with_extra_reason_appends_comment(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status'        => 'Дубль',
            'funnel_reason' => 'extra',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertTrue($order->funnel_exclude);
        $this->assertStringContainsString('доп. к другому заказу', $order->comment);
    }

    public function test_dubl_with_duplicate_reason_does_not_change_comment(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id, ['comment' => 'Исходный комментарий']);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status'        => 'Дубль',
            'funnel_reason' => 'duplicate',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertTrue($order->funnel_exclude);
        $this->assertSame('Исходный комментарий', $order->comment);
    }

    public function test_spam_does_not_set_funnel_exclude(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status' => 'Спам',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Спам', $order->status);
        $this->assertFalse($order->funnel_exclude);
    }

    public function test_leaving_dubl_clears_funnel_exclude(): void
    {
        $user  = $this->createActiveTenantUser();
        $order = $this->createOrder($user->tenant_id, [
            'status'         => 'Дубль',
            'funnel_exclude' => true,
        ]);

        $response = $this->actingAs($user)->patch("/orders/{$order->id}/status", [
            'status' => 'Позвонить',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('Позвонить', $order->status);
        $this->assertFalse($order->funnel_exclude);
    }

    public function test_bulk_update_to_dubl_sets_funnel_exclude(): void
    {
        $user   = $this->createActiveTenantUser();
        $orders = collect(range(1, 2))->map(fn () => $this->createOrder($user->tenant_id));

        $response = $this->actingAs($user)->patchJson('/orders/bulk-status', [
            'order_ids' => $orders->pluck('id')->all(),
            'status'    => 'Дубль',
        ]);

        $response->assertOk()
            ->assertJson(['updated' => 2, 'failed' => []]);

        foreach ($orders as $order) {
            $fresh = $order->fresh();
            $this->assertSame('Дубль', $fresh->status);
            $this->assertTrue($fresh->funnel_exclude);
        }
    }

    public function test_funnel_base_query_excludes_dubl_status(): void
    {
        $user = $this->createActiveTenantUser();
        $keep = $this->createOrder($user->tenant_id, ['status' => 'Позвонить']);
        $dubl = $this->createOrder($user->tenant_id, [
            'full_name'      => 'Дубль Без Исключения Тест',
            'status'         => 'Дубль',
            'funnel_exclude' => false,
        ]);

        $ids = Order::funnelBaseQuery(
            Order::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
        )->pluck('id')->all();

        $this->assertContains($keep->id, $ids);
        $this->assertNotContains($dubl->id, $ids);
    }

    public function test_funnel_base_query_excludes_flagged_non_dubl(): void
    {
        $user = $this->createActiveTenantUser();
        $keep = $this->createOrder($user->tenant_id, ['status' => 'Позвонить']);
        $test = $this->createOrder($user->tenant_id, [
            'full_name'      => 'Тестовый Заказ Исключённый',
            'status'         => 'Позвонить',
            'funnel_exclude' => true,
        ]);

        $ids = Order::funnelBaseQuery(
            Order::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
        )->pluck('id')->all();

        $this->assertContains($keep->id, $ids);
        $this->assertNotContains($test->id, $ids);
    }

    public function test_funnel_base_query_includes_spam_and_otkaz(): void
    {
        $user  = $this->createActiveTenantUser();
        $spam  = $this->createOrder($user->tenant_id, ['status' => 'Спам']);
        $otkaz = $this->createOrder($user->tenant_id, [
            'full_name' => 'Отказ Клиента Тестовый',
            'status'    => 'Отказ',
        ]);

        $ids = Order::funnelBaseQuery(
            Order::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
        )->pluck('id')->all();

        $this->assertContains($spam->id, $ids);
        $this->assertContains($otkaz->id, $ids);
    }
}
