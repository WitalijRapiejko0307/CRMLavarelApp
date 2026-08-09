<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Validates goods array contains at least one non-empty product name.
 */
class HasAtLeastOneGood implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
        }

        return false;
    }

    public function message(): string
    {
        return 'Укажите хотя бы один товар';
    }
}
