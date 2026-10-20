<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\Order;
use App\Services\FeedbackPromptService;
use App\Services\OnboardingService;
use App\Services\TrackingRunService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id'            => $user->id,
                    'name'          => $user->name,
                    'role'          => $user->role,
                    'theme'         => $user->theme ?? 'system',
                    'isSuperAdmin'  => $user->isSuperAdmin(),
                ] : null,
            ],
            'subscription' => fn () => $this->shareSubscription($user),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            'shop_name' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? TenantSetting::get('shop_name', 'BaseCRM') ?: 'BaseCRM'
                : 'BaseCRM',
            'tenant' => fn () => $this->shareTenant($user),
            'deletionScheduledFor' => fn () => $user && $user->isTenantUser()
                ? $user->tenant?->deletion_scheduled_for?->toIso8601String()
                : null,
            'tracking_auto_notice' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? app(TrackingRunService::class)->buildAutoNoticeForUser(auth()->user())
                : null,
            'order_delete' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? ['blocked_statuses' => Order::NON_DELETABLE_STATUSES]
                : null,
            'sr_sync_failures' => fn () => $this->shareSrSyncFailures($user),
            'onboarding' => fn () => $this->shareOnboarding($user),
            'feedback_prompt' => fn () => $this->shareFeedbackPrompt($user),
        ]);
    }

    protected function shareSubscription($user): ?array
    {
        if (!$user || !$user->isTenantUser()) {
            return null;
        }

        $tenant = $user->tenant;

        if (!$tenant) {
            return null;
        }

        $accessEndsAt = $tenant->accessEndsAt();

        $subscriptionStartsAt = null;
        if ($tenant->subscription_status === Tenant::STATUS_TRIAL
            && $tenant->subscription_prepaid_at !== null
            && $tenant->subscribed_at !== null) {
            $subscriptionStartsAt = $tenant->subscribed_at->toIso8601String();
        }

        $bannerDismissed = false;
        if ($user->subscription_banner_dismissed_for !== null && $accessEndsAt !== null) {
            $bannerDismissed = $user->subscription_banner_dismissed_for->equalTo($accessEndsAt);
        }

        return [
            'status'                 => $tenant->effectiveStatus(),
            'readOnly'               => $tenant->isReadOnly(),
            'trialDaysLeft'          => $tenant->trialDaysLeft(),
            'trialEndsAt'            => $tenant->trial_ends_at?->toIso8601String(),
            'deletion_scheduled_for' => $tenant->deletion_scheduled_for?->toIso8601String(),
            'accessEndsAt'           => $accessEndsAt?->toIso8601String(),
            'accessDaysLeft'         => $tenant->accessDaysLeft(),
            'subscriptionStartsAt'   => $subscriptionStartsAt,
            'bannerDismissed'        => $bannerDismissed,
        ];
    }

    protected function shareTenant($user): ?array
    {
        if (!$user || !$user->isTenantUser()) {
            return null;
        }

        $tenant = $user->tenant;

        if (!$tenant) {
            return null;
        }

        return [
            'id'   => $tenant->id,
            'type' => $tenant->type ?? \App\Models\Tenant::TYPE_STORE,
            'name' => $tenant->name,
        ];
    }

    protected function shareOnboarding($user): ?array
    {
        return app(OnboardingService::class)->forUser($user);
    }

    protected function shareFeedbackPrompt($user): ?string
    {
        if (!$user || !$user->isTenantUser() || $user->email_verified_at === null) {
            return null;
        }

        return app(FeedbackPromptService::class)->resolvePromptForUser($user);
    }

    protected function shareSrSyncFailures($user): ?array
    {
        if (!$user || !$user->isTenantUser()) {
            return null;
        }

        $raw = TenantSetting::get('sr_last_sync_failures', '');
        $failures = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($failures) || $failures === []) {
            return null;
        }

        $lastAt = TenantSetting::get('sr_last_sync_at');
        $seenAt = TenantSetting::get('sr_sync_failures_seen_at');

        if ($seenAt && $lastAt && $seenAt >= $lastAt) {
            return null;
        }

        return [
            'failures'  => $failures,
            'synced_at' => $lastAt,
        ];
    }
}
