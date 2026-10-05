<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureEmailVerified
{
    /**
     * @var array<int, string>
     */
    protected $exceptRouteNames = [
        'email.verify.show',
        'email.verify',
        'email.verify.resend',
        'logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->isSuperAdmin() || $user->email_verified_at !== null) {
            return $next($request);
        }

        $route = $request->route();
        if ($route && in_array($route->getName(), $this->exceptRouteNames, true)) {
            return $next($request);
        }

        if ($request->routeIs('login', 'login.post', 'register', 'register.post')) {
            return $next($request);
        }

        return redirect()->route('email.verify.show');
    }
}
