<?php

namespace App\Services;

use App\Models\MailBatch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Models\User;

class OnboardingService
{
    /**
     * Shared Inertia payload. Never includes secret values — only booleans and copy.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(?User $user): ?array
    {
        if (!$user || !$user->isTenantUser()) {
            return null;
        }

        $tenant = $user->tenant;

        if (!$tenant || !$tenant->isStore()) {
            return null;
        }

        $map = $this->settingsMap((int) $tenant->id);
        $belpostReady = $this->filled($map['auth_token_bp'] ?? null)
            && $this->filled($map['elc'] ?? null);
        $settingsDone = $belpostReady && $this->filled($map['belpost_sender_email'] ?? null);
        $productsDone = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
        $batchesDone = MailBatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
        $optionalSkipped = $user->onboarding_skip_optional_at !== null;
        $otherDone = $optionalSkipped || $this->otherServicesDone($tenant, $map);

        $dismissed = $user->onboarding_dismissed_at !== null;
        $isAdmin = $user->isAdmin();
        $isManager = $user->role === 'manager';

        if (!$isAdmin && !$isManager) {
            return $this->payload(
                false,
                $dismissed,
                null,
                [],
                false,
                $optionalSkipped,
                false,
                $belpostReady
            );
        }

        $steps = [];

        if ($isAdmin) {
            $steps[] = [
                'id'    => 'settings',
                'title' => 'Настройки Белпочты',
                'href'  => '/settings',
                'done'  => $settingsDone,
                'hint'  => 'Укажите токен, ЭЛС, срок хранения и email отправителя.',
            ];
        }

        $steps[] = [
            'id'    => 'products',
            'title' => 'Товары',
            'href'  => '/products',
            'done'  => $productsDone,
            'hint'  => 'Добавьте хотя бы один товар — его можно выбрать в заказе.',
        ];

        $steps[] = [
            'id'    => 'belpost',
            'title' => 'Партия на Белпочте',
            'href'  => '/belpost',
            'done'  => $batchesDone,
            'hint'  => 'Создайте заказ со статусом «Отправить» и оформите партию.',
        ];

        $coreComplete = ($isAdmin ? $settingsDone : true) && $productsDone && $batchesDone;

        if ($isAdmin && $coreComplete) {
            $steps[] = [
                'id'    => 'other',
                'title' => 'Другие сервисы',
                'href'  => '/settings',
                'done'  => $otherDone,
                'hint'  => 'Подключите Европочту, лендинг, колл-центр или SMS — когда будете готовы.',
            ];
        }

        $current = null;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $current = $step['id'];
                break;
            }
        }

        $completedCount = count(array_filter($steps, static function (array $step): bool {
            return $step['done'];
        }));

        $allDone = $current === null;
        $visible = !$dismissed && !$allDone;
        $settingsFocus = $isAdmin && !$settingsDone && !$dismissed;
        $canSkipOptional = $isAdmin && $coreComplete && !$otherDone && !$dismissed;

        return $this->payload(
            $visible,
            $dismissed,
            $current,
            $steps,
            $canSkipOptional,
            $optionalSkipped,
            $settingsFocus,
            $belpostReady,
            $completedCount,
            count($steps)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    protected function payload(
        bool $visible,
        bool $dismissed,
        ?string $current,
        array $steps,
        bool $canSkipOptional,
        bool $optionalSkipped,
        bool $settingsFocus,
        bool $belpostReady,
        int $completedCount = 0,
        int $total = 0
    ): array {
        return [
            'visible'            => $visible,
            'dismissed'          => $dismissed,
            'current_step'       => $current,
            'completed_count'    => $completedCount,
            'total'              => $total,
            'steps'              => $steps,
            'can_skip_optional'  => $canSkipOptional,
            'optional_skipped'   => $optionalSkipped,
            'settings_focus'     => $settingsFocus,
            'belpost_ready'      => $belpostReady,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function settingsMap(int $tenantId): array
    {
        return TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->mapWithKeys(static function (TenantSetting $row) {
                return [$row->key => (string) $row->value];
            })
            ->all();
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function otherServicesDone(Tenant $tenant, array $map): bool
    {
        $epNew = $this->filled($map['token_ep'] ?? null)
            && $this->filled($map['contractor_unn'] ?? null);
        $epLegacy = $this->filled($map['login_name_ep'] ?? null)
            && $this->filled($map['password_ep'] ?? null);
        $sr = (($map['sr_enabled'] ?? '') === '1')
            && $this->filled($map['api_token_call_centr'] ?? null);
        $sms = $this->filled($map['token_sms_by'] ?? null);
        $blacks = $this->filled($map['api_key_blacks_by'] ?? null);
        $cc = TenantConnection::query()
            ->where('store_tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantConnection::STATUS_PENDING,
                TenantConnection::STATUS_ACTIVE,
            ])
            ->exists();

        return $epNew || $epLegacy || $sr || $sms || $blacks || $cc;
    }

    protected function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
