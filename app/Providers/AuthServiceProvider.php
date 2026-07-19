<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    public function boot(): void
    {
        $this->registerPolicies();

        // Admin gate: true when the authenticated user has role 'admin'
        Gate::define('admin', fn ($user) => $user->role === 'admin');

        // User management: only admin can create/update/delete users
        Gate::define('manage-users', fn ($user) => $user->role === 'admin');

        // Settings: admin + manager can manage settings
        Gate::define('manage-settings', fn ($user) => in_array($user->role, ['admin', 'manager']));

        // Finance: admin + manager can access finance module
        Gate::define('view-finances', fn ($user) => in_array($user->role, ['admin', 'manager']));
    }
}
