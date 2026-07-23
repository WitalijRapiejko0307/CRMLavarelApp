<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();

            if ($user->isSuperAdmin()) {
                return redirect('/admin/tenants');
            }

            if ($user->isTenantUser()) {
                app()->instance('current_tenant_id', $user->tenant_id);
            }
        }

        return $next($request);
    }
}
