<?php

namespace App\Support;

/**
 * Normalizes Belarus phone numbers to 375XXXXXXXXX (12 digits).
 */
class PhoneNormalizer
{
    /**
     * @param  string|null $phone
     * @return string|null
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return $phone;
        }

        $len = strlen($digits);

        if ($len === 9) {
            return '375' . $digits;
        }

        if ($len === 11 && substr($digits, 0, 2) === '80') {
            return '375' . substr($digits, 2);
        }

        if ($len === 12 && substr($digits, 0, 3) === '375') {
            return $digits;
        }

        return $digits;
    }

    /**
     * Last 9 digits — local mobile number without country/operator prefix.
     */
    public static function lastNineDigits(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone ?? '');

        if ($digits === '') {
            return '';
        }

        return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    }
}
