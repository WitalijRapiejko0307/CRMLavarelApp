<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'tenant_id',
        'phone_key',
        'phone',
        'full_name',
        'city',
        'street',
        'building',
        'housing',
        'apartment',
        'poste_restante',
        'delivery_type',
        'belpost_address_id',
        'europochta_store_id',
        'last_order_id',
        'last_order_at',
        'orders_count',
    ];

    protected $casts = [
        'poste_restante' => 'boolean',
        'last_order_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
