<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class BelpostLabelLog extends Model
{
    public const KIND_BLANKS = 'blanks';
    public const KIND_OPIS   = 'opis';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_ERROR   = 'error';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'mail_batch_id',
        'user_id',
        'kind',
        'label_size',
        'orders_count',
        'result',
        'error_code',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }
}
