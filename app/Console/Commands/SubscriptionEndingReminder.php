<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionEndingMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SubscriptionEndingReminder extends Command
{
    protected $signature = 'tenants:subscription-ending-reminder';

    protected $description = 'Send 5-day subscription ending reminder emails to tenant admins';

    public function handle(): int
    {
        $timezone = config('app.timezone');
        $targetDay = Carbon::now($timezone)->addDays(5);

        $tenants = Tenant::query()
            ->whereIn('subscription_status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE])
            ->get();

        foreach ($tenants as $tenant) {
            $endsAt = $tenant->accessEndsAt();
            if ($endsAt === null) {
                continue;
            }

            $endsAtInTz = $endsAt->timezone($timezone);
            if (! $endsAtInTz->isSameDay($targetDay)) {
                continue;
            }

            $dateLabel = $endsAtInTz->locale('ru')->isoFormat('D MMMM YYYY');

            User::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', 'admin')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->orderBy('id')
                ->each(function (User $admin) use ($tenant, $dateLabel): void {
                    Mail::to($admin->email)->send(new SubscriptionEndingMail($tenant, $dateLabel));
                });
        }

        return self::SUCCESS;
    }
}
