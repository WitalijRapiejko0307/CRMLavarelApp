<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantConnection extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'store_tenant_id',
        'call_center_tenant_id',
        'status',
        'requested_at',
        'approved_at',
        'rejected_at',
        'disconnected_at',
    ];

    protected $casts = [
        'requested_at'    => 'datetime',
        'approved_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'store_tenant_id');
    }

    public function callCenter(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'call_center_tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
