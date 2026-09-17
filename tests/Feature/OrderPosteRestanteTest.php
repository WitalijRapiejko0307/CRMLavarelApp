<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderPosteRestanteTest extends TestCase
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
            'name'                => 'Poste Restante Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'poste-restante-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    public function test_store_poste_restante_fills_street_without_house(): void
    {
        $user = $this->createStoreAdmin();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'      => 'Иванов Иван',
            'phone'          => '291234567',
            'status'         => 'Позвонить',
            'city'           => '220000 г. Минск',
            'street'         => '',
            'building'       => '',
            'poste_restante' => 1,
            'goods'          => ['Товар А'],
            'quantities'     => [1],
            'prices'         => [10],
        ]);

        $response->assertRedirect();
        $this->assertSame(302, $response->status());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertTrue($order->poste_restante);
        $this->assertSame(Order::POSTE_RESTANTE_STREET, $order->street);
        $this->assertSame('220000 г. Минск', $order->city);
        $this->assertNull($order->building);
    }

    public function test_store_without_poste_restante_keeps_empty_street(): void
    {
        $user = $this->createStoreAdmin();

        $response = $this->actingAs($user)->post('/orders', [
            'full_name'  => 'Иванов Иван',
            'phone'      => '291234567',
            'status'     => 'Позвонить',
            'city'       => 'Минск',
            'street'     => '',
            'building'   => '',
            'goods'      => ['Товар А'],
            'quantities' => [1],
            'prices'     => [10],
        ]);

        $response->assertRedirect();

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertFalse($order->poste_restante);
        $this->assertSame('', (string) $order->street);
    }

    public function test_update_can_set_poste_restante_and_clear_building(): void
    {
        $user  = $this->createStoreAdmin();
        $order = Order::create([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван',
            'phone'         => '375291234567',
            'status'        => 'Позвонить',
            'city'          => 'Минск',
            'street'        => 'Ленина',
            'building'      => '10',
            'goods'         => ['Товар А'],
            'quantities'    => [1],
            'prices'        => [10],
            'poste_restante'=> false,
        ]);

        $response = $this->actingAs($user)->from("/orders/{$order->id}")->put("/orders/{$order->id}", [
            'poste_restante' => 1,
            'city'           => 'Минск',
            'street'         => '',
            'building'       => '',
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertTrue($order->poste_restante);
        $this->assertSame(Order::POSTE_RESTANTE_STREET, $order->street);
        $this->assertNull($order->building);
    }

    public function test_belpost_eligible_orders_include_poste_restante(): void
    {
        $user  = $this->createStoreAdmin();
        $order = Order::create([
            'tenant_id'      => $user->tenant_id,
            'full_name'      => 'Петров Пётр',
            'phone'          => '375291234568',
            'status'         => 'Отправить',
            'delivery_type'  => 'belpost',
            'track_number'   => null,
            'city'           => '220000 г. Минск',
            'street'         => Order::POSTE_RESTANTE_STREET,
            'building'       => null,
            'poste_restante' => true,
            'goods'          => ['Товар А'],
            'quantities'     => [1],
            'prices'         => [10],
        ]);

        $this->actingAs($user)->get('/belpost')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Belpost/Batch')
                ->has('eligibleOrders', 1)
                ->where('eligibleOrders.0.id', $order->id)
                ->where('eligibleOrders.0.poste_restante', true)
            );
    }
}
