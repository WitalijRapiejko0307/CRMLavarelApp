<?php

namespace App\Support;

/**
 * CRM status <-> SalesRender status name.
 *
 * Outgoing (CRM -> SR) pairs are one-to-one. SR "Отмена" is an incoming-only synonym of "Отменен".
 * CRM statuses without a pair are never sent to SalesRender.
 */
final class SrStatusMap
{
    /** CRM status => SalesRender status name. */
    public const OUTGOING = [
        'Заказать'      => 'Принят',
        'Отказ(Ошибка)' => 'Отменен',
        'Дубль'         => 'Дубли',
        'Спам'          => 'Спам',
    ];

    /** SalesRender statuses that map to a CRM status only on the way in. */
    public const INCOMING_ONLY = [
        'Отмена' => 'Отказ(Ошибка)',
    ];

    /** CRM status → SR status name, or null when the CRM status is not transferred. */
    public static function toSr(?string $crmStatus): ?string
    {
        return $crmStatus !== null ? (self::OUTGOING[$crmStatus] ?? null) : null;
    }

    /** SR status name → CRM status, or null when unknown. */
    public static function toCrm(?string $srStatus): ?string
    {
        if ($srStatus === null) {
            return null;
        }

        $flipped = array_flip(self::OUTGOING);

        return $flipped[$srStatus] ?? (self::INCOMING_ONLY[$srStatus] ?? null);
    }

    /** All SalesRender statuses that close a lead (used to detect an independent change by the call center). */
    public static function srFinalNames(): array
    {
        return array_merge(array_values(self::OUTGOING), array_keys(self::INCOMING_ONLY));
    }
}
