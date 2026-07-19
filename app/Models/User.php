<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public const ROLES = ['admin', 'manager', 'operator'];

    public const THEMES = ['light', 'dark', 'system'];

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'theme',
        'tracking_auto_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'     => 'datetime',
        'tracking_auto_seen_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Resolve theme preference to light or dark (for server-side rendering).
     * Frontend handles system via matchMedia.
     */
    public function resolvedTheme(): string
    {
        if ($this->theme === 'dark') {
            return 'dark';
        }
        if ($this->theme === 'light') {
            return 'light';
        }

        // system — use request hint or default to light
        $prefersDark = request()->header('Sec-CH-Prefers-Color-Scheme') === 'dark'
            || (isset($_COOKIE['prefers-color-scheme']) && $_COOKIE['prefers-color-scheme'] === 'dark');

        return $prefersDark ? 'dark' : 'light';
    }
}
