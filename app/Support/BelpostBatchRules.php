<?php

namespace App\Support;

/**
 * Weight / shelf-life limits for Belpost batch types (mirrors GS BelpostBatchModes.gs).
 */
class BelpostBatchRules
{
    public const PARTIAL_RECEIPT_TYPES = [
        'ecommerce_standard',
        'ecommerce_elite',
        'ecommerce_express',
    ];

    /**
     * @return array{skip_weight: bool, min: int, max: int, requires_shelf_life: bool}|null
     */
    public static function rulesForType(string $type): ?array
    {
        $t = trim($type);
        if ($t === '') {
            return null;
        }

        if ($t === 'ordered_postcard') {
            return ['skip_weight' => true, 'min' => 0, 'max' => 0, 'requires_shelf_life' => false];
        }

        $orderedSmall = [
            'ordered_letter',
            'ordered_parcel_post',
            'ordered_small_package',
            'small_package_declare_value',
        ];
        if (in_array($t, $orderedSmall, true)) {
            return ['skip_weight' => false, 'min' => 1, 'max' => 2000, 'requires_shelf_life' => false];
        }

        if ($t === 'package' || $t === 'package_declare_value') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 50000, 'requires_shelf_life' => false];
        }

        if ($t === 'ems') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 50000, 'requires_shelf_life' => false];
        }

        if ($t === 'ecommerce_economical' || $t === 'ecommerce_standard') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 30000, 'requires_shelf_life' => true];
        }

        if ($t === 'ecommerce_elite') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 50000, 'requires_shelf_life' => true];
        }

        if ($t === 'ecommerce_express') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 10000, 'requires_shelf_life' => true];
        }

        if ($t === 'ecommerce_light') {
            return ['skip_weight' => false, 'min' => 1, 'max' => 500, 'requires_shelf_life' => true];
        }

        if ($t === 'ecommerce_optima') {
            return ['skip_weight' => false, 'min' => 501, 'max' => 1000, 'requires_shelf_life' => true];
        }

        return [
            'skip_weight'         => false,
            'min'                 => 1,
            'max'                 => 50000,
            'requires_shelf_life' => str_contains($t, 'ecommerce'),
        ];
    }

    public static function validateWeight(string $type, int $weightGrams): ?string
    {
        $rules = self::rulesForType($type);
        if (!$rules || $rules['skip_weight']) {
            return null;
        }

        if ($weightGrams <= 0) {
            return 'Поле «Вес» (рассчитанный по товарам): значение '
                . $weightGrams
                . ', допустимо для выбранного вида партии от '
                . $rules['min'] . ' до ' . $rules['max'] . ' г.';
        }

        if ($weightGrams < $rules['min'] || $weightGrams > $rules['max']) {
            return 'Поле «Вес» (рассчитанный по товарам): значение '
                . $weightGrams
                . ', допустимо для выбранного вида партии от '
                . $rules['min'] . ' до ' . $rules['max'] . ' г.';
        }

        return null;
    }

    public static function validateShelfLife(string $type, mixed $raw): ?string
    {
        $rules = self::rulesForType($type);
        if (!$rules || !$rules['requires_shelf_life']) {
            return null;
        }

        $shelf = is_numeric($raw) ? (int) $raw : 0;
        if ($shelf <= 0) {
            return 'Поле «Срок хранения»: значение «' . (string) $raw
                . '», для E-commerce допустимо от 10 до 30 дней.';
        }

        if ($shelf < 10 || $shelf > 30) {
            return 'Поле «Срок хранения»: значение ' . $shelf
                . ', для E-commerce допустимо от 10 до 30 дней.';
        }

        return null;
    }

    public static function validateEcommerceEmail(string $type, string $email): ?string
    {
        if (!str_contains($type, 'ecommerce')) {
            return null;
        }

        if (trim($email) !== '') {
            return null;
        }

        return 'Поле «E-mail уведомления»: пусто, для E-commerce укажите e-mail отправителя в настройках CRM.';
    }
}
