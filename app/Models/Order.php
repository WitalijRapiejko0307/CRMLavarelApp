<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Full order lifecycle statuses.
     *
     * New lead    → Позвонить → Перезвонить → Недозвон* / Сомнения / Отдал заявку → Заказать → Отправить
     * Belpost/EP  → Оформлен → (Передан на почту) → Отправлено → В отделении → Возврат в пути → Забрать деньги
     * Revenue     → Завершен (operator confirms money received) → Посчитан (sumOrder processed)
     * Closed      → Возврат | Отказ | Отказ(Ошибка) | Дубль
     */
    public const STATUSES = [
        'Позвонить',
        'Перезвонить',
        'Недозвон',
        'Недозвон1',
        'Недозвон2',
        'Сомнения',
        'Отдал заявку',
        'Заказать',
        'Подтвержден',
        'Отправить',
        'Оформлен',
        'Передан на почту',
        'Отправлено',
        'В отделении',
        'Возврат в пути',
        'Забрать деньги',
        'Завершен',
        'Посчитан',
        'Возврат',
        'Отказ',
        'Отказ(Ошибка)',
        'Спам',
        'Дубль',
    ];

    /** Statuses that record meaningful call-center work (handlers column, analytics). */
    public const WORK_STATUSES = [
        'Подтвержден',
        'Заказать',
        'Отказ',
        'Отказ(Ошибка)',
        'Спам',
        'Недозвон',
        'Недозвон1',
        'Недозвон2',
    ];

    /** Statuses still in postal tracking (Belpost / Europochta). */
    public const TRACKING_STATUSES = [
        'Оформлен',
        'Передан на почту',
        'Отправлено',
        'В отделении',
        'Возврат в пути',
    ];

    /** Orders still in call / pre-mail workflow (used for CC backfill and phone duplicate detection). */
    public const ACTIVE_STATUSES = [
        'Позвонить',
        'Перезвонить',
        'Недозвон',
        'Недозвон1',
        'Недозвон2',
        'Сомнения',
        'Отдал заявку',
        'Заказать',
        'Подтвержден',
        'Отправить',
    ];

    /** Statuses a call-center operator may set (call phase + closing). */
    public const CALL_CENTER_STATUSES = [
        'Позвонить',
        'Перезвонить',
        'Недозвон',
        'Недозвон1',
        'Недозвон2',
        'Сомнения',
        'Отдал заявку',
        'Заказать',
        'Подтвержден',
        'Отказ',
        'Отказ(Ошибка)',
        'Спам',
        'Дубль',
    ];

    /** Fields a call-center operator may update. */
    public const CALL_CENTER_EDITABLE_FIELDS = [
        'status',
        'full_name',
        'phone',
        'city',
        'street',
        'building',
        'housing',
        'apartment',
        'goods',
        'quantities',
        'prices',
        'delivery_type',
        'source',
        'funnel_exclude',
        'comment',
        'upsell',
        'cross_sell',
        'poste_restante',
        'callback_at',
    ];

    /** Statuses that must not be deleted (revenue final, active tracking, active call-center). */
    public const NON_DELETABLE_STATUSES = [
        'Завершен',
        'Посчитан',
        'Оформлен',
        'Передан на почту',
        'Отправлено',
        'В отделении',
        'Возврат в пути',
        'Забрать деньги',
        'Позвонить',
        'Перезвонить',
        'Заказать',
        'Подтвержден',
        'Отправить',
        'Сомнения',
        'Отдал заявку',
    ];

    /** Statuses that require confirmation before bulk update (side effects in OrderObserver). */
    public const BULK_CONFIRM_STATUSES = [
        'Отправлено',
        'Возврат',
        'Завершен',
    ];

    public const FUNNEL_EXCLUDE_REASONS = [
        'duplicate' => 'дубль',
        'test'      => 'тест',
        'extra'     => 'доп. к другому заказу',
    ];

    public const POSTE_RESTANTE_STREET = 'До востребования';

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

    public static function isDeletable(self $order): bool
    {
        return !in_array($order->status, self::NON_DELETABLE_STATUSES, true);
    }

    public static function funnelBaseQuery(Builder $query): Builder
    {
        return $query->where('funnel_exclude', false)->where('status', '!=', 'Дубль');
    }

    protected $fillable = [
        'tenant_id',
        'call_center_tenant_id',
        'last_updated_by_user_id',
        'assigned_user_id',
        'external_id',
        'full_name',
        'status',
        'status_changed_at',
        'callback_at',
        'goods',
        'quantities',
        'city',
        'street',
        'building',
        'housing',
        'apartment',
        'poste_restante',
        'phone',
        'prices',
        'track_number',
        'delivery_type',
        'sms_log',
        'source',
        'funnel_exclude',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'comment',
        'upsell',
        'cross_sell',
        'ops_id',
        'belpost_address_id',
        'mail_batch_id',
        'created_at',
    ];

    protected $casts = [
        'goods'             => 'array',
        'quantities'        => 'array',
        'prices'            => 'array',
        'funnel_exclude'    => 'boolean',
        'poste_restante'    => 'boolean',
        'status_changed_at' => 'datetime',
        'callback_at'       => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Order $order) {
            if ($order->delivery_type === null || $order->delivery_type === '') {
                $order->delivery_type = 'belpost';
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function callCenterTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'call_center_tenant_id');
    }

    public function lastUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by_user_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function storeConnection()
    {
        return $this->hasOne(TenantConnection::class, 'store_tenant_id', 'tenant_id')
            ->where('call_center_tenant_id', $this->call_center_tenant_id);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function mailBatch(): BelongsTo
    {
        return $this->belongsTo(MailBatch::class);
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
            $this->poste_restante ? self::POSTE_RESTANTE_STREET : $this->street,
            $this->poste_restante ? null : $this->building,
            $this->housing ? 'корп. ' . $this->housing : null,
            $this->apartment ? 'кв. ' . $this->apartment : null,
        ]);
        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyPosteRestanteDefaults(array $data): array
    {
        if (empty($data['poste_restante'])) {
            return $data;
        }

        $data['poste_restante'] = true;
        $street = trim((string) ($data['street'] ?? ''));
        if ($street === '') {
            $data['street'] = self::POSTE_RESTANTE_STREET;
        }
        if (!array_key_exists('building', $data) || trim((string) $data['building']) === '') {
            $data['building'] = null;
        }

        return $data;
    }
}
