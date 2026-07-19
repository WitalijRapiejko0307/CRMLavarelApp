<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Full order lifecycle statuses.
     *
     * New lead    → Позвонить → Перезвонить → Заказать → Отправить
     * Belpost/EP  → Оформлен → (Передан на почту) → Отправлено → В отделении → Забрать деньги
     * Revenue     → Завершен (operator confirms money received) → Посчитан (sumOrder processed)
     * Closed      → Возврат | Отказ | Отказ(Ошибка) | Дубль
     */
    public const STATUSES = [
        'Позвонить',
        'Перезвонить',
        'Заказать',
        'Отправить',
        'Оформлен',
        'Передан на почту',
        'Отправлено',
        'В отделении',
        'Забрать деньги',
        'Завершен',
        'Посчитан',
        'Возврат',
        'Отказ',
        'Отказ(Ошибка)',
        'Дубль',
    ];

    public const DELIVERY_TYPES = [
        'belpost'    => 'Белпочта',
        'europochta' => 'Европочта',
        'courier'    => 'Курьер',
        'pickup'     => 'Самовывоз',
        'personal'   => 'Лично',
    ];

    public static function deliveryTypeRule(): string
    {
        return 'in:' . implode(',', array_keys(self::DELIVERY_TYPES));
    }

    protected $fillable = [
        'tenant_id',
        'external_id',
        'full_name',
        'status',
        'status_changed_at',
        'goods',
        'quantities',
        'city',
        'street',
        'building',
        'housing',
        'apartment',
        'phone',
        'prices',
        'track_number',
        'delivery_type',
        'sms_log',
        'source',
        'ops_id',
        'belpost_address_id',
    ];

    protected $casts = [
        'goods'             => 'array',
        'quantities'        => 'array',
        'prices'            => 'array',
        'status_changed_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * Human-readable label for the first product.
     */
    public function getFirstGoodAttribute(): string
    {
        $goods = $this->goods ?? [];
        return count($goods) > 0 ? $goods[0] : '—';
    }

    /**
     * Full address string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->city,
            $this->street,
            $this->building,
            $this->housing ? 'корп. ' . $this->housing : null,
            $this->apartment ? 'кв. ' . $this->apartment : null,
        ]);
        return implode(', ', $parts);
    }
}
