<?php

namespace App\Services;

use App\Jobs\SendDailyDigestJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DailyDigestScheduler
{
    public function __construct(
        protected DailyDigestService $digest
    ) {
    }

    public function dispatchDue(?Carbon $now = null): void
    {
        $now = ($now ?? Carbon::now(DailyDigestService::TIMEZONE))
            ->copy()
            ->timezone(DailyDigestService::TIMEZONE);

        foreach ($this->writableStoreTenants() as $tenant) {
            app()->instance('current_tenant_id', $tenant->id);

            if (!$this->isDue($tenant->id, $now)) {
                continue;
            }

            $lockKey = 'digest:queued:' . $tenant->id . ':' . $now->toDateString();
            if (!Cache::add($lockKey, 1, $now->copy()->addMinutes(6))) {
                continue;
            }

            dispatch(new SendDailyDigestJob($tenant->id, false));
        }
    }

    public function isDue(int $tenantId, Carbon $now): bool
    {
        app()->instance('current_tenant_id', $tenantId);

        if (TenantSetting::get('digest_enabled', '') !== '1') {
            return false;
        }

        $time = trim((string) TenantSetting::get('digest_time', ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return false;
        }

        if (!$this->digest->hasChannel($tenantId)) {
            return false;
        }

        $today = $now->copy()->timezone(DailyDigestService::TIMEZONE)->toDateString();
        if ((string) TenantSetting::get('digest_last_sent_on', '') === $today) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        $windowStart = $now->copy()->timezone(DailyDigestService::TIMEZONE)->setTime($hour, $minute, 0);
        $windowEnd   = $windowStart->copy()->addMinutes(5);

        return $now->gte($windowStart) && $now->lt($windowEnd);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    protected function writableStoreTenants()
    {
        return Tenant::whereIn('subscription_status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE])
            ->get()
            ->filter(fn (Tenant $tenant) => !$tenant->isReadOnly() && $tenant->isStore());
    }
}
