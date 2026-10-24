<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStockMovement extends Model
{
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IN  = 'in';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'product_id',
        'qty',
        'direction',
        'reason',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
