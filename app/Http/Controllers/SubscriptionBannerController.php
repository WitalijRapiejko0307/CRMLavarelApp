<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubscriptionBannerController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->isTenantUser()) {
            abort(403);
        }

        $tenant = $user->tenant;
        $endsAt = $tenant->accessEndsAt();

        if ($endsAt === null) {
            abort(422);
        }

        $user->subscription_banner_dismissed_for = $endsAt;
        $user->save();

        return back();
    }
}
