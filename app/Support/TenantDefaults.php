<?php

namespace App\Support;

use App\Models\TenantSetting;

/**
 * Tenant-level defaults that must work outside an authenticated request
 * (webhook, CSV import, model hooks): everything is read by explicit tenant id.
 */
class TenantDefaults
{
    public const COURIERS = ['belpost', 'europochta'];

    /**
     * Priority delivery service for new orders. Falls back to "belpost" when the setting is
     * missing or when Europochta is selected but not connected.
     */
    public static function courier(?int $tenantId): string
    {
        if (!$tenantId) {
            return 'belpost';
        }

        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['default_courier', 'ep_api_version', 'token_ep', 'login_name_ep', 'password_ep'])
            ->get()
            ->pluck('value', 'key')
            ->map(function ($v) {
                return trim((string) $v);
            })
            ->all();

        $courier = $settings['default_courier'] ?? '';
        if (!in_array($courier, self::COURIERS, true)) {
            $courier = 'belpost';
        }

        if ($courier === 'europochta' && !self::europochtaConnected($settings)) {
            $courier = 'belpost';
        }

        return $courier;
    }

    /**
     * Sender OPS configured in tenant settings.
     *
     * @return array{id: int|null, label: string}
     */
    public static function senderOps(?int $tenantId): array
    {
        if (!$tenantId) {
            return ['id' => null, 'label' => ''];
        }

        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['warehouse_id_start', 'warehouse_label'])
            ->get()
            ->pluck('value', 'key')
            ->all();

        $id = trim((string) ($settings['warehouse_id_start'] ?? ''));

        return [
            'id'    => ctype_digit($id) ? (int) $id : null,
            'label' => trim((string) ($settings['warehouse_label'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $settings
     */
    private static function europochtaConnected(array $settings): bool
    {
        $legacy = ($settings['ep_api_version'] ?? '') === 'legacy';

        return $legacy
            ? (($settings['login_name_ep'] ?? '') !== '' && ($settings['password_ep'] ?? '') !== '')
            : (($settings['token_ep'] ?? '') !== '');
    }
}
