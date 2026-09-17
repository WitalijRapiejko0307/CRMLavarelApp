<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OrderSegment
{
    public const TO_SHIP_BELPOST     = 'to_ship_belpost';
    public const TO_SHIP_EUROPOCHTA  = 'to_ship_europochta';
    public const NO_TRACK            = 'no_track';
    public const IN_TRANSIT          = 'in_transit';
    public const AT_BRANCH           = 'at_branch';
    public const STUCK               = 'stuck';
    public const RETURNS             = 'returns';
    public const CALLBACK_DUE        = 'callback_due';

    public static function labels(): array
    {
        return [
            self::TO_SHIP_BELPOST    => 'к отправке Бел',
            self::TO_SHIP_EUROPOCHTA => 'к отправке Евро',
            self::NO_TRACK           => 'без трека',
            self::IN_TRANSIT         => 'в пути',
            self::AT_BRANCH          => '≤5 дней',
            self::STUCK              => '>5 дней',
            self::RETURNS            => 'возвраты',
            self::CALLBACK_DUE       => 'перезвон',
        ];
    }

    public static function apply(Builder $q, string $segment): Builder
    {
        return match ($segment) {
            self::TO_SHIP_BELPOST    => $q->where('status', 'Отправить')->where('delivery_type', 'belpost'),
            self::TO_SHIP_EUROPOCHTA => $q->where('status', 'Отправить')->where('delivery_type', 'europochta'),
            self::NO_TRACK           => self::applyNoTrack($q),
            self::IN_TRANSIT         => $q->whereIn('status', ['Отправлено', 'Передан на почту']),
            self::AT_BRANCH          => self::applyAtBranch($q),
            self::STUCK              => self::applyStuck($q),
            self::RETURNS            => $q->whereIn('status', ['Возврат', 'Возврат в пути']),
            self::CALLBACK_DUE       => $q->where('status', 'Перезвонить')
                ->whereNotNull('callback_at')
                ->where('callback_at', '<=', Carbon::now()),
            default                  => $q,
        };
    }

    /** Statuses still in the postal pipeline without a track (not call-center, not return-in-transit). */
    public static function noTrackStatuses(): array
    {
        return array_values(array_filter(
            array_merge(['Отправить'], Order::TRACKING_STATUSES),
            fn (string $status) => $status !== 'Возврат в пути',
        ));
    }

    private static function applyNoTrack(Builder $q): Builder
    {
        return $q->where(function (Builder $inner) {
            $inner->whereNull('track_number')
                ->orWhere('track_number', '');
        })->whereIn('status', self::noTrackStatuses());
    }

    private static function applyAtBranch(Builder $q): Builder
    {
        return $q->where('status', 'В отделении')
            ->whereNotNull('status_changed_at')
            ->where('status_changed_at', '>=', Carbon::now()->subDays(5));
    }

    private static function applyStuck(Builder $q): Builder
    {
        return $q->where('status', 'В отделении')
            ->whereNotNull('status_changed_at')
            ->where('status_changed_at', '<', Carbon::now()->subDays(5));
    }
}
