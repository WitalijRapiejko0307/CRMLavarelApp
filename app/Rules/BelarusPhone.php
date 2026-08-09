<?php

namespace App\Rules;

use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Validation\Rule;

/**
 * Validates Belarus mobile/phone number as 375XXXXXXXXX (12 digits).
 */
class BelarusPhone implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $normalized = PhoneNormalizer::normalize($value);

        if ($normalized === null || $normalized === '') {
            return false;
        }

        $digits = preg_replace('/\D/', '', $normalized);

        return strlen($digits) === 12
            && str_starts_with($digits, '375')
            && strlen(PhoneNormalizer::lastNineDigits($digits)) === 9;
    }

    public function message(): string
    {
        return 'Укажите корректный белорусский номер (375XXXXXXXXX)';
    }
}
