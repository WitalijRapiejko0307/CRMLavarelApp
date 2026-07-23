<?php

namespace App\Http\Middleware;

use App\Models\TenantSetting;
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
            'tracking_auto_notice' => fn () => auth()->check() && auth()->user()->isTenantUser()
                ? app(TrackingRunService::class)->buildAutoNoticeForUser(auth()->user())
                : null,
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
}
