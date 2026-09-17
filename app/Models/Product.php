<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'page_url',
        'stock',
        'weight',
        'upsell_name',
        'upsell_price',
        'upsell_text',
        'cross_name',
        'cross_price',
        'cross_text',
        'manager_note',
        'sr_item_id',
        'sold_count',
        'sold_amount',
    ];

    protected $casts = [
        'stock'        => 'integer',
        'weight'       => 'float',
        'upsell_price' => 'float',
        'cross_price'  => 'float',
        'sr_item_id'   => 'integer',
        'sold_count'   => 'integer',
        'sold_amount'  => 'float',
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
