<?php

namespace App\Console;

use App\Console\Commands\SumOrdersCommand;
use App\Jobs\SyncSalesRenderJob;
use App\Models\Tenant;
use App\Services\TrackingRunService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Process queued jobs every minute (database driver, no Redis).
        // withoutOverlapping prevents multiple workers from piling up.
        $schedule->command('queue:work --stop-when-empty --tries=3')
            ->everyMinute()
            ->withoutOverlapping();

        // Morning auto tracking check at 07:30 Europe/Minsk — once per tenant per day.
        $schedule->call(function () {
            $service = app(TrackingRunService::class);
            foreach (Tenant::all() as $tenant) {
                if ($service->alreadyRanAutoToday($tenant->id)) {
                    continue;
                }
                try {
                    $service->startRun($tenant->id, 'auto');
                } catch (\Throwable $e) {
                    Log::warning('Auto tracking dispatch failed', [
                        'tenant_id' => $tenant->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        })->dailyAt('07:30')
          ->timezone('Europe/Minsk')
          ->name('dispatch-tracking-morning')
          ->withoutOverlapping();

        // Phase 3: dispatch one SalesRender sync job per tenant every 5 minutes.
        $schedule->call(function () {
            foreach (Tenant::all() as $tenant) {
                dispatch(new SyncSalesRenderJob($tenant->id));
            }
        })->everyFiveMinutes()->name('dispatch-salesrender')->withoutOverlapping();

        // Phase 4: aggregate revenue from completed orders daily at midnight.
        $schedule->command(SumOrdersCommand::class)
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->name('sum-orders');
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
