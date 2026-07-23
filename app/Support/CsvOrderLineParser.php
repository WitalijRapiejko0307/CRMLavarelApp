<?php

namespace App\Support;

use InvalidArgumentException;

class CsvOrderLineParser
{
    /**
     * @return array{goods: string[], quantities: array<int|float>, prices: array<int|float>}
     */
    public static function parse(string $goodsRaw, ?string $qtyRaw, ?string $pricesRaw): array
    {
        $goods = array_values(array_filter(
            array_map('trim', explode(',', $goodsRaw)),
            fn (string $item) => $item !== ''
        ));

        if ($goods === []) {
            return ['goods' => [], 'quantities' => [], 'prices' => []];
        }

        $quantities = self::splitNumbers($qtyRaw);
        $prices     = self::splitNumbers($pricesRaw);

        if (count($goods) === 1) {
            if ($quantities === []) {
                $quantities = [1];
            }
            if ($prices === []) {
                $prices = [0];
            }
        }

        $goodsCount = count($goods);
        $qtyCount   = count($quantities);
        $priceCount = count($prices);

        if ($goodsCount !== $qtyCount || $goodsCount !== $priceCount) {
            throw new InvalidArgumentException(sprintf(
                'Количество товаров (%d) не совпадает с количеством позиций в штуках (%d) и ценах (%d)',
                $goodsCount,
                $qtyCount,
                $priceCount
            ));
        }

        return [
            'goods'      => $goods,
            'quantities' => $quantities,
            'prices'     => $prices,
        ];
    }

    /**
     * @return array<int|float>
     */
    private static function splitNumbers(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return array_values(array_map([self::class, 'normalizeNumber'], $parts));
    }

    /**
     * @return int|float
     */
    private static function normalizeNumber(string $value)
    {
        $normalized = str_replace(' ', '', trim($value));
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || !is_numeric($normalized)) {
            return $normalized;
        }

        return str_contains($normalized, '.')
            ? (float) $normalized
            : (int) $normalized;
    }
}
