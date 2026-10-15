<?php

namespace App\Console\Commands;

use App\Mail\TenantDeletionReminderMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class PurgeScheduledTenants extends Command
{
    protected $signature = 'tenants:purge-scheduled';

    protected $description = 'Delete tenants past deletion_scheduled_for and send 7-day reminder emails';

    public function handle(TenantDeletionService $deletionService): int
    {
        $this->purgeDueTenants($deletionService);
        $this->sendSevenDayReminders();

        return self::SUCCESS;
    }

    private function purgeDueTenants(TenantDeletionService $deletionService): void
    {
        $due = Tenant::query()
            ->whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->get();

        foreach ($due as $tenant) {
            $deletionService->deleteTenant($tenant, null);
            $this->line("Deleted tenant #{$tenant->id} ({$tenant->name}).");
        }
    }

    private function sendSevenDayReminders(): void
    {
        $timezone = config('app.timezone');
        $reminderDay = Carbon::now($timezone)->addDays(7);
        $dayStart = $reminderDay->copy()->startOfDay();
        $dayEnd = $reminderDay->copy()->endOfDay();

        $tenants = Tenant::query()
            ->whereNotNull('deletion_scheduled_for')
            ->whereBetween('deletion_scheduled_for', [$dayStart, $dayEnd])
            ->get();

        foreach ($tenants as $tenant) {
            $scheduled = $tenant->deletion_scheduled_for->timezone($timezone);
            if (! $scheduled->isSameDay($reminderDay)) {
                continue;
            }

            $dateLabel = $scheduled->locale('ru')->isoFormat('D MMMM YYYY');

            User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'admin')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->orderBy('id')
                ->each(function (User $admin) use ($tenant, $dateLabel): void {
                    Mail::to($admin->email)->send(new TenantDeletionReminderMail($tenant, $dateLabel));
                });
        }
    }
}
