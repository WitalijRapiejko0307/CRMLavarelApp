<?php

namespace App\Services;

use App\Jobs\UpdateTrackingJob;
use App\Models\Order;
use App\Models\TenantSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class TrackingRunService
{
    private const LOCK_SECONDS     = 900;
    private const PROGRESS_SECONDS = 900;
    private const TIMEZONE         = 'Europe/Minsk';

    /**
     * @return Builder<Order>
     */
    public function activeOrdersQuery(int $tenantId): Builder
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('delivery_type', ['belpost', 'europochta'])
            ->whereNotNull('track_number')
            ->whereIn('status', ['Оформлен', 'Передан на почту', 'Отправлено', 'В отделении']);
    }

    public function countActiveOrders(int $tenantId): int
    {
        return $this->activeOrdersQuery($tenantId)->count();
    }

    public function lockKey(int $tenantId): string
    {
        return "tracking:lock:{$tenantId}";
    }

    public function progressKey(int $tenantId): string
    {
        return "tracking:progress:{$tenantId}";
    }

    /**
     * @return array{ok: true, total: int, status: 'running'}|array{ok: false, progress: array|null}
     */
    public function startRun(int $tenantId, string $source): array
    {
        $lock = Cache::lock($this->lockKey($tenantId), self::LOCK_SECONDS);

        if (!$lock->get()) {
            return [
                'ok'       => false,
                'progress' => $this->getProgress($tenantId),
            ];
        }

        try {
            $total = $this->countActiveOrders($tenantId);

            $this->setProgress($tenantId, [
                'status'      => 'running',
                'checked'     => 0,
                'total'       => $total,
                'errors'      => 0,
                'source'      => $source,
                'started_at'  => now()->toIso8601String(),
                'finished_at' => null,
            ]);

            dispatch(new UpdateTrackingJob($tenantId, $source));

            return [
                'ok'     => true,
                'total'  => $total,
                'status' => 'running',
            ];
        } catch (\Throwable $e) {
            $lock->release();
            throw $e;
        }
    }

    public function getProgress(int $tenantId): ?array
    {
        return Cache::get($this->progressKey($tenantId));
    }

    public function incrementProgress(int $tenantId, bool $hadError = false): void
    {
        $progress = $this->getProgress($tenantId);
        if (!$progress) {
            return;
        }

        $progress['checked'] = ($progress['checked'] ?? 0) + 1;
        if ($hadError) {
            $progress['errors'] = ($progress['errors'] ?? 0) + 1;
        }

        $this->setProgress($tenantId, $progress);
    }

    /**
     * @param  array{total?: int, checked?: int, errors?: int}  $stats
     */
    public function finishRun(int $tenantId, string $source, string $status, array $stats = []): void
    {
        $progress = $this->getProgress($tenantId) ?? [];

        $progress['status']      = $status;
        $progress['checked']       = $stats['checked'] ?? ($progress['checked'] ?? 0);
        $progress['total']         = $stats['total'] ?? ($progress['total'] ?? 0);
        $progress['errors']        = $stats['errors'] ?? ($progress['errors'] ?? 0);
        $progress['finished_at']   = now()->toIso8601String();

        $this->setProgress($tenantId, $progress);

        Cache::lock($this->lockKey($tenantId))->forceRelease();
    }

    public function saveAutoStats(int $tenantId, int $total, int $checked, int $errors): void
    {
        $now = now()->toIso8601String();

        TenantSetting::put($tenantId, 'tracking_last_auto_at', $now);
        TenantSetting::put($tenantId, 'tracking_last_auto_total', (string) $total);
        TenantSetting::put($tenantId, 'tracking_last_auto_checked', (string) $checked);
        TenantSetting::put($tenantId, 'tracking_last_auto_errors', (string) $errors);
    }

    public function alreadyRanAutoToday(int $tenantId): bool
    {
        $this->setTenantContext($tenantId);

        $lastAt = TenantSetting::get('tracking_last_auto_at');
        if (!$lastAt) {
            return false;
        }

        $last  = Carbon::parse($lastAt)->timezone(self::TIMEZONE);
        $today = now()->timezone(self::TIMEZONE)->startOfDay();

        return $last->gte($today);
    }

    public function buildAutoNoticeForUser(User $user): ?array
    {
        $this->setTenantContext($user->tenant_id);

        $lastAt = TenantSetting::get('tracking_last_auto_at');
        if (!$lastAt) {
            return null;
        }

        $lastAtCarbon = Carbon::parse($lastAt)->timezone(self::TIMEZONE);
        $today        = now()->timezone(self::TIMEZONE)->startOfDay();

        if ($lastAtCarbon->lt($today)) {
            return null;
        }

        if ($user->tracking_auto_seen_at && $user->tracking_auto_seen_at->gte($lastAtCarbon)) {
            return null;
        }

        return [
            'last_auto_at' => $lastAt,
            'total'        => (int) TenantSetting::get('tracking_last_auto_total', 0),
            'checked'      => (int) TenantSetting::get('tracking_last_auto_checked', 0),
            'errors'       => (int) TenantSetting::get('tracking_last_auto_errors', 0),
        ];
    }

    private function setProgress(int $tenantId, array $progress): void
    {
        Cache::put($this->progressKey($tenantId), $progress, self::PROGRESS_SECONDS);
    }

    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
