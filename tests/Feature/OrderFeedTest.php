<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_returns_only_orders_updated_since_timestamp(): void
    {
        $tenant = Tenant::create([
            'name'                => 'Store',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'store@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $oldOrder = Order::create([
            'tenant_id'  => $tenant->id,
            'full_name'  => 'Old Order',
            'status'     => 'Позвонить',
            'updated_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        $newOrder = Order::create([
            'tenant_id' => $tenant->id,
            'full_name' => 'New Order',
            'status'    => 'Позвонить',
        ]);

        $since = now()->subMinutes(30)->toIso8601String();

        $response = $this->actingAs($user)->getJson("/api/orders/feed?since={$since}");

        $response->assertOk();
        $ids = collect($response->json('orders'))->pluck('id')->all();

        $this->assertContains($newOrder->id, $ids);
        $this->assertNotContains($oldOrder->id, $ids);
    }
}
