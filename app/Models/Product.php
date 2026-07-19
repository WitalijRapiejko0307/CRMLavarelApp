<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'stock',
        'weight',
        'sr_item_id',
        'sold_count',
        'sold_amount',
    ];

    protected $casts = [
        'stock'       => 'integer',
        'weight'      => 'float',
        'sr_item_id'  => 'integer',
        'sold_count'  => 'integer',
        'sold_amount' => 'float',
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
     * Find product by exact name within current tenant scope.
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }
}
