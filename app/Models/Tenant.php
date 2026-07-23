<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'subscription_status',
        'trial_ends_at',
        'subscribed_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'created_at'    => 'datetime',
        'trial_ends_at' => 'datetime',
        'subscribed_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function setting(string $key): ?string
    {
        return $this->settings()->where('key', $key)->value('value');
    }

    public function effectiveStatus(): string
    {
        if ($this->subscription_status === self::STATUS_TRIAL
            && $this->trial_ends_at
            && now()->gte($this->trial_ends_at)) {
            return self::STATUS_EXPIRED;
        }

        return $this->subscription_status;
    }

    public function isReadOnly(): bool
    {
        $status = $this->effectiveStatus();

        if ($status === self::STATUS_ACTIVE) {
            return false;
        }

        if ($status === self::STATUS_TRIAL) {
            return false;
        }

        return true;
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->subscription_status !== self::STATUS_TRIAL || !$this->trial_ends_at) {
            return null;
        }

        $days = now()->startOfDay()->diffInDays($this->trial_ends_at->startOfDay(), false);

        return max(0, (int) $days);
    }

    public function activate(): void
    {
        $this->update([
            'subscription_status' => self::STATUS_ACTIVE,
            'subscribed_at'       => now(),
            'trial_ends_at'       => null,
        ]);
    }

    public function extendTrial(int $days): void
    {
        $base = ($this->trial_ends_at && $this->trial_ends_at->isFuture())
            ? $this->trial_ends_at
            : now();

        $this->update([
            'subscription_status' => self::STATUS_TRIAL,
            'trial_ends_at'       => $base->copy()->addDays($days),
        ]);
    }
}
