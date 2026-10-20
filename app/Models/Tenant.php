<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    public const TYPE_STORE = 'store';
    public const TYPE_CALL_CENTER = 'call_center';

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'type',
        'subscription_status',
        'trial_ends_at',
        'subscribed_at',
        'subscription_ends_at',
        'subscription_prepaid_at',
        'leads_enabled',
        'archived_at',
        'deletion_requested_by',
        'deletion_requested_at',
        'deletion_scheduled_for',
    ];

    public $timestamps = false;

    protected $casts = [
        'created_at'              => 'datetime',
        'trial_ends_at'           => 'datetime',
        'subscribed_at'           => 'datetime',
        'subscription_ends_at'    => 'datetime',
        'subscription_prepaid_at' => 'datetime',
        'leads_enabled'           => 'boolean',
        'archived_at'             => 'datetime',
        'deletion_requested_at'   => 'datetime',
        'deletion_scheduled_for'  => 'datetime',
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

    public function storeConnections(): HasMany
    {
        return $this->hasMany(TenantConnection::class, 'store_tenant_id');
    }

    public function callCenterConnections(): HasMany
    {
        return $this->hasMany(TenantConnection::class, 'call_center_tenant_id');
    }

    public function activeStoreConnection(): HasOne
    {
        return $this->hasOne(TenantConnection::class, 'store_tenant_id')
            ->where('status', TenantConnection::STATUS_ACTIVE);
    }

    public function isStore(): bool
    {
        return ($this->type ?? self::TYPE_STORE) === self::TYPE_STORE;
    }

    public function isCallCenter(): bool
    {
        return $this->type === self::TYPE_CALL_CENTER;
    }

    public function setting(string $key): ?string
    {
        return $this->settings()->where('key', $key)->value('value');
    }

    public function accessEndsAt(): ?Carbon
    {
        if ($this->subscription_status === self::STATUS_TRIAL && $this->trial_ends_at) {
            return $this->trial_ends_at;
        }

        if ($this->subscription_ends_at) {
            return $this->subscription_ends_at;
        }

        return null;
    }

    public function promotePrepaidTrial(): void
    {
        if ($this->subscription_status !== self::STATUS_TRIAL) {
            return;
        }

        if ($this->subscription_prepaid_at === null || $this->trial_ends_at === null) {
            return;
        }

        if (now()->lt($this->trial_ends_at)) {
            return;
        }

        $this->update(['subscription_status' => self::STATUS_ACTIVE]);
    }

    public function effectiveStatus(): string
    {
        $this->promotePrepaidTrial();

        if ($this->subscription_status === self::STATUS_TRIAL
            && $this->trial_ends_at
            && now()->gte($this->trial_ends_at)
            && $this->subscription_prepaid_at === null) {
            return self::STATUS_EXPIRED;
        }

        if ($this->subscription_status === self::STATUS_ACTIVE
            && $this->subscription_ends_at
            && now()->gte($this->subscription_ends_at)) {
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

    public function accessDaysLeft(): ?int
    {
        $endsAt = $this->accessEndsAt();

        if ($endsAt === null) {
            return null;
        }

        $days = now()->startOfDay()->diffInDays($endsAt->startOfDay(), false);

        return max(0, (int) $days);
    }

    public function activate(): void
    {
        if ($this->subscription_status === self::STATUS_TRIAL
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture()) {
            $this->update([
                'subscription_prepaid_at' => now(),
                'subscribed_at'         => $this->trial_ends_at,
                'subscription_ends_at'  => $this->trial_ends_at->copy()->addMonth(),
            ]);

            return;
        }

        $this->update([
            'subscription_status'   => self::STATUS_ACTIVE,
            'subscription_prepaid_at' => now(),
            'subscribed_at'         => now(),
            'subscription_ends_at'  => now()->addMonth(),
            'trial_ends_at'         => null,
        ]);
    }

    public function extendSubscription(): void
    {
        $base = ($this->subscription_ends_at && $this->subscription_ends_at->isFuture())
            ? $this->subscription_ends_at
            : now();

        $updates = [
            'subscription_ends_at'    => $base->copy()->addMonth(),
            'subscription_status'     => self::STATUS_ACTIVE,
            'subscription_prepaid_at' => now(),
        ];

        if ($this->subscribed_at === null) {
            $updates['subscribed_at'] = $base;
        }

        $this->update($updates);
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

    public function acceptsLeads(): bool
    {
        if (!$this->leads_enabled || $this->archived_at !== null) {
            return false;
        }

        $status = $this->effectiveStatus();

        return $status === self::STATUS_TRIAL || $status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scheduleDeletion(int $userId): void
    {
        $this->update([
            'deletion_requested_by'  => $userId,
            'deletion_requested_at'  => now(),
            'deletion_scheduled_for' => now()->addDays(30),
        ]);
    }

    public function cancelDeletion(): void
    {
        $this->update([
            'deletion_requested_by'  => null,
            'deletion_requested_at'  => null,
            'deletion_scheduled_for' => null,
        ]);
    }

    public function archive(): void
    {
        $updates = ['leads_enabled' => false];

        if ($this->archived_at === null) {
            $updates['archived_at'] = now();
        }

        $this->update($updates);
    }

    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }
}
