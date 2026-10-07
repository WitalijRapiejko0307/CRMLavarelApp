<?php

namespace Tests\Feature;

use App\Jobs\UpdateTrackingJob;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\TrackingRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BelpostTrackingProxyJobTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_URL = 'https://script.google.com/macros/s/test-belpost-proxy/exec';

    protected function tearDown(): void
    {
        putenv('BELPOST_TRACKING_PROXY_URL');
        putenv('BELPOST_TRACKING_PROXY_SECRET');
        unset($_ENV['BELPOST_TRACKING_PROXY_URL'], $_ENV['BELPOST_TRACKING_PROXY_SECRET']);

        parent::tearDown();
    }

    private function setProxyEnv(string $url = self::PROXY_URL, string $secret = 'proxy-secret'): void
    {
        putenv('BELPOST_TRACKING_PROXY_URL=' . $url);
        putenv('BELPOST_TRACKING_PROXY_SECRET=' . $secret);
        $_ENV['BELPOST_TRACKING_PROXY_URL'] = $url;
        $_ENV['BELPOST_TRACKING_PROXY_SECRET'] = $secret;
    }

    private function createTenantWithBelpostToken(): Tenant
    {
        $tenant = Tenant::create([
            'name'                => 'Belpost Proxy Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        TenantSetting::put($tenant->id, 'auth_token_bp', 'tenant-bp-token');

        return $tenant;
    }

    private function seedRun(TrackingRunService $service, int $tenantId, int $total): void
    {
        Cache::lock($service->lockKey($tenantId), 900)->get();

        Cache::put($service->progressKey($tenantId), [
            'status'           => 'running',
            'checked'          => 0,
            'total'            => $total,
            'errors'           => 0,
            'source'           => 'manual',
            'cancel_requested' => false,
            'started_at'       => now()->toIso8601String(),
            'finished_at'      => null,
        ], 900);
    }

    /**
     * @return \Closure(\Illuminate\Http\Client\Request): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response
     */
    private function proxyResponder(array $mapTracks, ?array $directSearchResults = null): \Closure
    {
        return function ($request) use ($mapTracks, $directSearchResults) {
            if (str_contains($request->url(), 'api.belpost.by')) {
                return Http::response(['message' => 'should not be called'], 500);
            }

            $data = $request->data();

            if (!array_key_exists('items', $data)) {
                return Http::response(['map' => $mapTracks], 200);
            }

            if ($directSearchResults !== null) {
                return Http::response(['results' => $directSearchResults], 200);
            }

            return Http::response(['error' => 'unexpected direct search'], 500);
        };
    }

    private function assertNoDraftRowsForTenant(int $tenantId): void
    {
        $this->assertSame(
            0,
            DB::table('belpost_tracking_drafts')->where('tenant_id', $tenantId)->count()
        );
    }

    private function countProxyRequests(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'script.google.com'))
            ->count();
    }

    public function test_belpost_requests_go_to_proxy_not_belpost_api(): void
    {
        $this->setProxyEnv();

        $tenant  = $this->createTenantWithBelpostToken();
        $service = app(TrackingRunService::class);
        $this->seedRun($service, $tenant->id, 1);

        $order = Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Клиент',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'track_number'  => 'BY123456789BY',
        ]);

        Http::fake([
            'api.belpost.by/*' => Http::response(['message' => 'should not be called'], 500),
            self::PROXY_URL    => $this->proxyResponder([
                [
                    'track'     => 'BY123456789BY',
                    'event'     => 'Отправлено',
                    'createdAt' => '2026-10-07 09:00:00',
                ],
            ]),
        ]);

        $job = new UpdateTrackingJob($tenant->id, 'manual');
        $job->handle($service);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'script.google.com')) {
                return false;
            }

            $data = $request->data();

            return ($data['secret'] ?? '') === 'proxy-secret'
                && ($data['authToken'] ?? '') === 'tenant-bp-token'
                && !array_key_exists('items', $data);
        });

        Http::assertNotSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'script.google.com')
                && array_key_exists('items', $data);
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.belpost.by');
        });

        $this->assertSame(1, $this->countProxyRequests());

        $order->refresh();
        $this->assertSame('Отправлено', $order->status);

        $progress = $service->getProgress($tenant->id);
        $this->assertSame(1, $progress['checked']);
        $this->assertSame(0, $progress['errors']);

        $this->assertNoDraftRowsForTenant($tenant->id);
    }

    public function test_found_false_does_not_change_status(): void
    {
        $this->setProxyEnv();

        $tenant  = $this->createTenantWithBelpostToken();
        $service = app(TrackingRunService::class);
        $this->seedRun($service, $tenant->id, 1);

        $order = Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Клиент',
            'status'        => 'Отправлено',
            'delivery_type' => 'belpost',
            'track_number'  => 'BY-NOT-FOUND',
        ]);

        Http::fake([
            self::PROXY_URL => $this->proxyResponder([], [
                [
                    'track'        => 'BY-NOT-FOUND',
                    'found'        => false,
                    'event'        => null,
                    'createdAt'    => null,
                    'targetStatus' => null,
                ],
            ]),
        ]);

        (new UpdateTrackingJob($tenant->id, 'manual'))->handle($service);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'script.google.com')) {
                return false;
            }

            $data = $request->data();

            return array_key_exists('items', $data)
                && count($data['items']) === 1
                && ($data['items'][0]['track'] ?? '') === 'BY-NOT-FOUND';
        });

        $order->refresh();
        $this->assertSame('Отправлено', $order->status);

        $progress = $service->getProgress($tenant->id);
        $this->assertSame(0, $progress['errors']);

        $this->assertNoDraftRowsForTenant($tenant->id);
    }

    public function test_direct_search_forbidden_counts_as_error_and_clears_drafts(): void
    {
        $this->setProxyEnv();

        $tenant  = $this->createTenantWithBelpostToken();
        $service = app(TrackingRunService::class);
        $this->seedRun($service, $tenant->id, 1);

        $order = Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Клиент',
            'status'        => 'Отправлено',
            'delivery_type' => 'belpost',
            'track_number'  => 'BY-DIRECT-403',
        ]);

        $mapCalled = false;

        Http::fake(function ($request) use (&$mapCalled) {
            $data = $request->data();

            if (!array_key_exists('items', $data)) {
                $mapCalled = true;

                return Http::response(['map' => []], 200);
            }

            return Http::response(['error' => 'Forbidden'], 403);
        });

        (new UpdateTrackingJob($tenant->id, 'manual'))->handle($service);

        $this->assertTrue($mapCalled);

        $order->refresh();
        $this->assertSame('Отправлено', $order->status);

        $progress = $service->getProgress($tenant->id);
        $this->assertSame(1, $progress['errors']);

        $this->assertNoDraftRowsForTenant($tenant->id);
    }

    public function test_proxy_forbidden_counts_as_error_without_status_change(): void
    {
        $this->setProxyEnv();

        $tenant  = $this->createTenantWithBelpostToken();
        $service = app(TrackingRunService::class);
        $this->seedRun($service, $tenant->id, 1);

        $order = Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Клиент',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'track_number'  => 'BY403',
        ]);

        Http::fake([
            self::PROXY_URL => Http::response(['error' => 'Forbidden'], 403),
        ]);

        (new UpdateTrackingJob($tenant->id, 'manual'))->handle($service);

        $order->refresh();
        $this->assertSame('Оформлен', $order->status);

        $progress = $service->getProgress($tenant->id);
        $this->assertSame(1, $progress['errors']);

        $this->assertNoDraftRowsForTenant($tenant->id);
    }

    public function test_empty_proxy_url_skips_belpost_without_http(): void
    {
        $this->setProxyEnv('');

        $tenant  = $this->createTenantWithBelpostToken();
        $service = app(TrackingRunService::class);
        $this->seedRun($service, $tenant->id, 1);

        $order = Order::create([
            'tenant_id'     => $tenant->id,
            'full_name'     => 'Клиент',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'track_number'  => 'BY-SKIP',
        ]);

        Http::fake();

        (new UpdateTrackingJob($tenant->id, 'manual'))->handle($service);

        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame('Оформлен', $order->status);

        $progress = $service->getProgress($tenant->id);
        $this->assertSame(1, $progress['checked']);
        $this->assertSame(0, $progress['errors']);
    }
}
