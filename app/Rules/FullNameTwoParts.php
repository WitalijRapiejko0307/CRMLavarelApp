<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Validates full name as at least two parts (surname, name) for Belpost.
 */
class FullNameTwoParts implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        $parts      = array_values(array_filter(explode(' ', $normalized), fn ($p) => $p !== ''));

        if (count($parts) < 2) {
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
        return 'Обязательно введите фамилию и имя через пробел (отчество необязательно)';
    }
}
