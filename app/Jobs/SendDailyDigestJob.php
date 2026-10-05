<?php

namespace App\Jobs;

use App\Services\DailyDigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDailyDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public int $tenantId,
        public bool $force = false
    ) {
    }

    /**
     * @return array{ok: bool, skipped?: bool, error?: string, message?: string}
     */
    public function handle(DailyDigestService $digest): array
    {
        $this->setTenantContext($this->tenantId);

        return $digest->send($this->tenantId, $this->force);
    }

    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
