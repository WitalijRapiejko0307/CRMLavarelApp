<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class EnsureTenantType
{
    public function handle(Request $request, Closure $next, string ...$types)
    {
        $user = $request->user();

        if (!$user || !$user->isTenantUser()) {
            abort(403);
        }

        $tenant = $user->tenant;

        if (!$tenant || !in_array($tenant->type ?? Tenant::TYPE_STORE, $types, true)) {
            abort(403, 'Этот раздел недоступен для вашего типа компании.');
        }

        return $next($request);
    }
}
