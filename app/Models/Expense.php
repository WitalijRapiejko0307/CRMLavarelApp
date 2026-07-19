<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = [
        'Реклама',
        'Логистика',
        'Закупка товара',
        'Связь',
        'Зарплата',
        'Офис',
        'Прочее',
    ];

    protected $fillable = [
        'tenant_id',
        'amount',
        'category',
        'description',
        'date',
    ];

    protected $casts = [
        'amount' => 'float',
        'date'   => 'date',
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
