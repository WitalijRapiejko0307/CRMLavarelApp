<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_id',
        'number',
        'name',
        'stock',
        'sold_count',
        'sold_amount',
    ];

    protected $casts = [
        'number'      => 'integer',
        'stock'       => 'integer',
        'sold_count'  => 'integer',
        'sold_amount' => 'float',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Label shown next to the product name; falls back to the number. */
    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : 'Вариация ' . $this->number;
    }
}
