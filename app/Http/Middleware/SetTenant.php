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
            app()->instance('current_tenant_id', Auth::user()->tenant_id);
        }

        return $next($request);
    }
}
