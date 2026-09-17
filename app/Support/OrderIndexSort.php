<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class OrderIndexSort
{
    public const DEFAULT_COLUMN = 'created_at';
    public const DEFAULT_DIR    = 'desc';

    /** @var array<string, string> request key → orders column */
    public const COLUMNS = [
        'id'            => 'id',
        'created_at'    => 'created_at',
        'full_name'     => 'full_name',
        'status'        => 'status',
        'phone'         => 'phone',
        'city'          => 'city',
        'track_number'  => 'track_number',
        'delivery_type' => 'delivery_type',
    ];

    /**
     * @return array{0: string, 1: string}
     */
    public static function resolve(?string $sort, ?string $dir): array
    {
        if (!isset(self::COLUMNS[$sort])) {
            return [self::DEFAULT_COLUMN, self::DEFAULT_DIR];
        }

        $direction = strtolower((string) $dir);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = in_array($sort, ['created_at', 'id'], true)
                ? self::DEFAULT_DIR
                : 'asc';
        }

        return [$sort, $direction];
    }

    public static function apply(Builder $query, ?string $sort, ?string $dir): Builder
    {
        [$column, $direction] = self::resolve($sort, $dir);
        $qualified = 'orders.'.self::COLUMNS[$column];

        return $query
            ->orderBy($qualified, $direction)
            ->orderByDesc('orders.id');
    }
}
