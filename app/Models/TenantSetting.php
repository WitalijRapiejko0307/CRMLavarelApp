<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'key', 'value'];

    /**
     * Value is stored encrypted in the database.
     * Using Crypt manually to support PHP 7.4 + Laravel 8.
     */
    protected $casts = [
        'value' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Shortcut: get decrypted setting value for current tenant.
     */
    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Set or update a setting for current tenant.
     */
    public static function put(int $tenantId, string $key, string $value): void
    {
        static::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value]
        );
    }
}
