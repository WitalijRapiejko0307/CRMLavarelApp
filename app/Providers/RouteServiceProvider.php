<?php

namespace App\Providers;

use App\Models\Order;
use App\Support\CallCenterOrderQuery;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/orders';

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureBindings();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('webhook', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('connections', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function configureBindings(): void
    {
        Route::bind('order', function (string $value) {
            $user = auth()->user();

            if (!$user || !$user->isTenantUser()) {
                abort(404);
            }

            $tenant = $user->tenant;

            if ($tenant->isCallCenter()) {
                return CallCenterOrderQuery::forTenant($tenant->id)->findOrFail($value);
            }

            return Order::findOrFail($value);
        });
    }
}
