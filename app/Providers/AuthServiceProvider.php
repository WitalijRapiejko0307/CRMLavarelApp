<?php

namespace App\Providers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Order::class => OrderPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('super-admin', fn ($user) => $user->isSuperAdmin());
        Gate::define('manage-tenants', fn ($user) => $user->isSuperAdmin());

        // Admin gate: true when the authenticated user has role 'admin'
        Gate::define('admin', fn ($user) => $user->isTenantUser() && $user->role === 'admin');

        // User management: only admin can create/update/delete users
        Gate::define('manage-users', fn ($user) => $user->isTenantUser() && $user->role === 'admin');

        // Settings: admin + manager can view; only admin can edit
        Gate::define('view-settings', fn ($user) => $user->isTenantUser() && in_array($user->role, ['admin', 'manager']));
        Gate::define('manage-settings', fn ($user) => $user->isTenantUser() && $user->role === 'admin');

        // Finance: admin + manager can access finance module
        Gate::define('view-finances', fn ($user) => $user->isTenantUser() && in_array($user->role, ['admin', 'manager']));

        Gate::define('delete-orders', fn ($user) => $user->isTenantUser() && $user->role === 'admin' && $user->tenant?->isStore());

        Gate::define('manage-connections', fn ($user) => $user->isTenantUser() && $user->role === 'admin');

        Gate::define('approve-connections', fn ($user) =>
            $user->isTenantUser()
            && $user->role === 'admin'
            && $user->tenant?->isCallCenter()
        );

        Gate::define('view-analytics', fn ($user) =>
            $user->isTenantUser()
            && $user->tenant?->isCallCenter()
            && in_array($user->role, ['admin', 'manager', 'operator'], true)
        );

        Gate::define('view-team-analytics', fn ($user) =>
            $user->isTenantUser()
            && $user->tenant?->isCallCenter()
            && in_array($user->role, ['admin', 'manager'], true)
        );
    }
}
