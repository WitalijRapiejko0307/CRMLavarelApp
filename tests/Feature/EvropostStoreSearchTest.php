<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\EvropostStoreSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvropostStoreSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_search_by_ops_number_and_address_returns_office(): void
    {
        $user = $this->createStoreUserWithToken();
        $this->fakeStoresDirectory();

        $byNumber = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=777');
        $byNumber->assertOk();
        $byNumber->assertJsonPath('items.0.id', 42);
        $byNumber->assertJsonPath('items.0.ops_number', '777');
        $this->assertStringContainsString('Купалы', $byNumber->json('items.0.label'));

        $byAddress = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=' . urlencode('Гродно Купалы'));
        $byAddress->assertOk();
        $byAddress->assertJsonPath('items.0.id', 42);
        $byAddress->assertJsonPath('items.0.city', 'Гродно');
    }

    public function test_store_and_update_persist_europochta_store_id_and_ops_id(): void
    {
        $user = $this->createStoreUserWithToken();

        $create = $this->actingAs($user)->post('/orders', [
            'full_name'            => 'Иванов Иван',
            'phone'                => '291234567',
            'status'               => 'Позвонить',
            'goods'                => ['Товар А'],
            'quantities'           => [1],
            'prices'               => [10],
            'delivery_type'        => 'europochta',
            'city'                 => 'Гродно',
            'street'               => 'ул. Купалы',
            'building'             => '1',
            'ops_id'               => '777',
            'europochta_store_id'  => 42,
        ]);

        $create->assertRedirect();
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('777', $order->ops_id);
        $this->assertSame(42, (int) $order->europochta_store_id);

        $update = $this->actingAs($user)->put('/orders/' . $order->id, [
            'ops_id'              => '778',
            'europochta_store_id' => 99,
            'city'                => 'Гродно',
            'street'              => 'ул. Купалы',
            'building'            => '2',
        ]);

        $update->assertRedirect();
        $order->refresh();
        $this->assertSame('778', $order->ops_id);
        $this->assertSame(99, (int) $order->europochta_store_id);
    }

    public function test_search_without_token_returns_422(): void
    {
        $user = $this->createStoreUser();

        Http::fake();

        $response = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=777');

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('европочт', $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_api_401_is_not_cached_as_empty_directory(): void
    {
        $user = $this->createStoreUserWithToken();
        $calls = 0;

        Http::fake(function ($request) use (&$calls) {
            if (str_contains($request->url(), '/api/external/stores')) {
                $calls++;
                if ($calls === 1) {
                    return Http::response(['message' => 'Unauthorized'], 401);
                }

                return Http::response([$this->grodnoOffice()], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $first = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=777');
        $first->assertStatus(422);
        $this->assertSame([], $first->json('items'));
        $this->assertNull(Cache::get(EvropostStoreSearchService::CACHE_KEY));

        $second = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=777');
        $second->assertOk();
        $second->assertJsonPath('items.0.id', 42);
        $this->assertSame(2, $calls);
    }

    public function test_short_query_returns_empty_items_without_http(): void
    {
        $user = $this->createStoreUserWithToken();
        Http::fake();

        $response = $this->actingAs($user)->getJson('/api/europochta/stores/search?q=7');

        $response->assertOk();
        $response->assertExactJson(['items' => []]);
        Http::assertNothingSent();
    }

    public function test_refresh_stores_clears_cache_so_next_search_hits_api(): void
    {
        $user = $this->createStoreUserWithToken();
        $this->fakeStoresDirectory();

        $this->actingAs($user)->getJson('/api/europochta/stores/search?q=777')->assertOk();
        $this->assertNotNull(Cache::get(EvropostStoreSearchService::CACHE_KEY));

        $this->actingAs($user)->postJson('/settings/europochta/refresh-stores')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(Cache::get(EvropostStoreSearchService::CACHE_KEY));
    }

    private function createStoreUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'EP Search ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'ep-search-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function createStoreUserWithToken(): User
    {
        $user = $this->createStoreUser();
        TenantSetting::put($user->tenant_id, 'token_ep', 'ep-token');
        TenantSetting::put($user->tenant_id, 'ep_api_version', 'new');

        return $user;
    }

    private function grodnoOffice(): array
    {
        return [
            'id'            => 42,
            'ops_number'    => '777',
            'ops_name'      => 'ОПС №777',
            'city'          => 'Гродно',
            'street'        => 'ул. Купалы',
            'house'         => '1',
            'name'          => 'Отделение Гродно',
            'working_hours' => '9-18',
        ];
    }

    private function fakeStoresDirectory(): void
    {
        $office = $this->grodnoOffice();

        Http::fake(function ($request) use ($office) {
            if (str_contains($request->url(), '/api/external/stores')) {
                return Http::response([
                    $office,
                    [
                        'id'         => 7,
                        'ops_number' => '100',
                        'ops_name'   => 'ОПС Минск',
                        'city'       => 'Минск',
                        'street'     => 'пр. Независимости',
                        'house'      => '10',
                        'name'       => 'Отделение Минск',
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });
    }
}
