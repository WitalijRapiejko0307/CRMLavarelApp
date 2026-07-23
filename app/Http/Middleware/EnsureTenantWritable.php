<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureTenantWritable
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user || $user->isSuperAdmin()) {
            return $next($request);
        }

        if ($this->isExcepted($request)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($user->isTenantUser() && $user->tenant && $user->tenant->isReadOnly()) {
            abort(403, 'Пробный период закончился. Доступен только просмотр.');
        }

        return $next($request);
    }

    protected function isExcepted(Request $request): bool
    {
        if ($request->is('logout') && $request->isMethod('POST')) {
            return true;
        }

        if ($request->is('settings/theme') && $request->isMethod('PATCH')) {
            return true;
        }

        return false;
    }
}
