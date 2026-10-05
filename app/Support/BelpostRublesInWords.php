<?php

namespace App\Support;

/**
 * Spell Belarus ruble amounts for Belpost partial-receipt opis (копейки always two digits).
 */
class BelpostRublesInWords
{
    private const ONES = [
        '', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять',
    ];

    private const ONES_F = [
        '', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять',
    ];

    private const TEENS = [
        'десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать',
        'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать',
    ];

    private const TENS = [
        '', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто',
    ];

    private const HUNDREDS = [
        '', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот',
    ];

    /**
     * @return string e.g. «восемьдесят рублей 00 копеек»
     */
    public static function format(int $rubles, int $kopecks = 0): string
    {
        $kopecks = max(0, min(99, $kopecks));
        $words   = $rubles === 0 ? 'ноль' : self::tripletsToWords($rubles, false);

        return trim($words) . ' ' . self::pluralRubles($rubles) . ' '
            . str_pad((string) $kopecks, 2, '0', STR_PAD_LEFT) . ' '
            . self::pluralKopecks($kopecks);
    }

    private static function tripletsToWords(int $number, bool $useFemaleOnes): string
    {
        if ($number === 0) {
            return '';
        }

        $parts = [];
        $scale = 0;

        while ($number > 0) {
            $chunk = $number % 1000;
            if ($chunk > 0) {
                $chunkWords = self::chunkToWords($chunk, $scale === 1);
                $scaleWord  = self::scaleWord($scale, $chunk);
                $parts[]    = trim($chunkWords . ($scaleWord !== '' ? ' ' . $scaleWord : ''));
            }
            $number = (int) floor($number / 1000);
            $scale++;
        }

        return implode(' ', array_reverse($parts));
    }

    private static function chunkToWords(int $chunk, bool $useFemaleOnes): string
    {
        $hundreds = (int) floor($chunk / 100);
        $rest     = $chunk % 100;
        $words    = [];

        if ($hundreds > 0) {
            $words[] = self::HUNDREDS[$hundreds];
        }

        if ($rest >= 10 && $rest <= 19) {
            $words[] = self::TEENS[$rest - 10];
        } else {
            $tens = (int) floor($rest / 10);
            $ones = $rest % 10;
            if ($tens > 0) {
                $words[] = self::TENS[$tens];
            }
            if ($ones > 0) {
                $words[] = ($useFemaleOnes ? self::ONES_F : self::ONES)[$ones];
            }
        }

        return implode(' ', $words);
    }

    private static function scaleWord(int $scale, int $chunk): string
    {
        if ($scale === 0) {
            return '';
        }

        $mod100 = $chunk % 100;
        $mod10  = $chunk % 10;

        if ($scale === 1) {
            return self::pickForm($mod100, $mod10, 'тысяча', 'тысячи', 'тысяч');
        }

        if ($scale === 2) {
            return self::pickForm($mod100, $mod10, 'миллион', 'миллиона', 'миллионов');
        }

        return '';
    }

    private static function pickForm(int $mod100, int $mod10, string $one, string $few, string $many): string
    {
        if ($mod100 >= 11 && $mod100 <= 19) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }

    private static function pluralRubles(int $n): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 19) {
            return 'рублей';
        }
        if ($mod10 === 1) {
            return 'рубль';
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return 'рубля';
        }

        return 'рублей';
    }

    private static function pluralKopecks(int $n): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 19) {
            return 'копеек';
        }
        if ($mod10 === 1) {
            return 'копейка';
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return 'копейки';
        }

        return 'копеек';
    }
}
