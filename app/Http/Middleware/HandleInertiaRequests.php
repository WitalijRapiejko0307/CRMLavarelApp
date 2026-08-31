<?php

namespace App\Http\Middleware;

use App\Models\TenantSetting;
use App\Models\Order;
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
            'tracking_auto_notice' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? app(TrackingRunService::class)->buildAutoNoticeForUser(auth()->user())
                : null,
            'order_delete' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? ['blocked_statuses' => Order::NON_DELETABLE_STATUSES]
                : null,
            'sr_sync_failures' => fn () => $this->shareSrSyncFailures($user),
            'onboarding' => fn () => $this->shareOnboarding($user),
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

        return [
            'status'        => $tenant->effectiveStatus(),
            'readOnly'      => $tenant->isReadOnly(),
            'trialDaysLeft' => $tenant->trialDaysLeft(),
            'trialEndsAt'   => $tenant->trial_ends_at?->toIso8601String(),
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
