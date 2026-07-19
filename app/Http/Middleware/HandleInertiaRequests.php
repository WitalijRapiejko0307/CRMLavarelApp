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
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id'    => $request->user()->id,
                    'name'  => $request->user()->name,
                    'role'  => $request->user()->role,
                    'theme' => $request->user()->theme ?? 'system',
                ] : null,
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            'shop_name' => fn () => auth()->check()
                ? TenantSetting::get('shop_name', 'BaseCRM') ?: 'BaseCRM'
                : 'BaseCRM',
            'tracking_auto_notice' => fn () => auth()->check()
                ? app(TrackingRunService::class)->buildAutoNoticeForUser(auth()->user())
                : null,
        ]);
    }
}
