<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Validates full name as three parts (surname, name, patronymic) for Belpost.
 */
class FullNameThreeParts implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        $parts      = array_values(array_filter(explode(' ', $normalized), fn ($p) => $p !== ''));

        if (count($parts) < 3) {
            return false;
        }

        foreach ($parts as $part) {
            if (mb_strlen($part) < 2) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return 'Укажите Фамилию, Имя и Отчество через пробел (как требует Белпочта)';
    }
}
