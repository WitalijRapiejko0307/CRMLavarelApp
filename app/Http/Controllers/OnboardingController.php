<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->onboarding_dismissed_at = now();
        $user->save();

        return back();
    }

    public function restore(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->onboarding_dismissed_at = null;
        $user->save();

        return back();
    }

    public function skipOptional(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403);
        }

        $user->onboarding_skip_optional_at = now();
        $user->save();

        return back();
    }
}
