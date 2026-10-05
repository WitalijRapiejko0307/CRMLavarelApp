<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationCodeService;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmailVerificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect(app(OnboardingService::class)->loginFallbackHref($user));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
        ]);
    }

    public function verify(Request $request, EmailVerificationCodeService $service)
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect(app(OnboardingService::class)->loginFallbackHref($user));
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $service->verify($user, $data['code']);

        $fallback = app(OnboardingService::class)->loginFallbackHref($user->fresh());

        return redirect($fallback);
    }

    public function resend(Request $request, EmailVerificationCodeService $service)
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect(app(OnboardingService::class)->loginFallbackHref($user));
        }

        $service->sendCode($user);

        return back()->with('message', 'Новый код отправлен на вашу почту.');
    }
}
