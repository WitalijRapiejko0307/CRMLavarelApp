<?php

namespace Tests\Feature;

use App\Jobs\UpdateTrackingJob;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TrackingRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrackingCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createActiveTenantAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Tracking Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'tracking-cancel@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function seedManualRun(TrackingRunService $service, int $tenantId, int $total = 2): void
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

    public function test_cancel_manual_run_sets_cancelled_and_releases_lock(): void
    {
        $user    = $this->createActiveTenantAdmin();
        $service = app(TrackingRunService::class);
        $tenantId = $user->tenant_id;

        $this->seedManualRun($service, $tenantId);

        Order::create([
            'tenant_id'     => $tenantId,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'track_number'  => 'BP123',
        ]);

        $service->requestCancel($tenantId);

        $job = new UpdateTrackingJob($tenantId, 'manual');
        $job->handle($service);

        $progress = $service->getProgress($tenantId);
        $this->assertSame('cancelled', $progress['status']);
        $this->assertFalse($service->isCancelRequested($tenantId));

        Queue::fake();
        $result = $service->startRun($tenantId, 'manual');
        $this->assertTrue($result['ok']);
    }

    public function test_cancel_on_auto_run_or_idle_returns_409(): void
    {
        $user    = $this->createActiveTenantAdmin();
        $service = app(TrackingRunService::class);
        $tenantId = $user->tenant_id;

        $response = $this->actingAs($user)->post('/orders/cancel-tracking');
        $response->assertStatus(409);

        Cache::lock($service->lockKey($tenantId), 900)->get();
        Cache::put($service->progressKey($tenantId), [
            'status'           => 'running',
            'checked'          => 0,
            'total'            => 1,
            'errors'           => 0,
            'source'           => 'auto',
            'cancel_requested' => false,
            'started_at'       => now()->toIso8601String(),
            'finished_at'      => null,
        ], 900);

        $response = $this->actingAs($user)->post('/orders/cancel-tracking');
        $response->assertStatus(409);
        $this->assertFalse($service->isCancelRequested($tenantId));
    }

    public function test_job_stops_cooperatively_when_cancel_requested(): void
    {
        $user     = $this->createActiveTenantAdmin();
        $service  = app(TrackingRunService::class);
        $tenantId = $user->tenant_id;

        $this->seedManualRun($service, $tenantId, 3);

        for ($i = 1; $i <= 3; $i++) {
            Order::create([
                'tenant_id'     => $tenantId,
                'full_name'     => "Клиент {$i}",
                'status'        => 'Оформлен',
                'delivery_type' => 'belpost',
                'track_number'  => "BP{$i}",
            ]);
        }

        $service->requestCancel($tenantId);

        $job = new UpdateTrackingJob($tenantId, 'manual');
        $job->handle($service);

        $progress = $service->getProgress($tenantId);
        $this->assertSame('cancelled', $progress['status']);
        $this->assertSame(1, $progress['checked']);
    }
}
