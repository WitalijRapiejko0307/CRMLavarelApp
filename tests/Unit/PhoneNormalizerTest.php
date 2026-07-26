<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /** @dataProvider normalizeProvider */
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    public function normalizeProvider(): array
    {
        return [
            '9 digits without prefix'       => ['291234567', '375291234567'],
            '9 digits with spaces'          => ['29 123 45 67', '375291234567'],
            '12 digits with 375'            => ['375291234567', '375291234567'],
            '12 digits with plus'           => ['+375291234567', '375291234567'],
            '11 digits with 80 prefix'      => ['80291234567', '375291234567'],
            '11 digits 80 with formatting'  => ['+80 (29) 123-45-67', '375291234567'],
            'unrecognized length kept'      => ['12345', '12345'],
        ];
    }

    /** @dataProvider lastNineProvider */
    public function test_last_nine_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::lastNineDigits($input));
    }

    public function lastNineProvider(): array
    {
        return [
            'from 9 digits'    => ['291234567', '291234567'],
            'from 375 prefix'  => ['375291234567', '291234567'],
            'from 80 prefix'   => ['80291234567', '291234567'],
            'with formatting'  => ['+375 29 123-45-67', '291234567'],
            'short number'     => ['12345', '12345'],
        ];
    }

    public function test_normalize_null_and_empty(): void
    {
        $this->assertNull(PhoneNormalizer::normalize(null));
        $this->assertSame('', PhoneNormalizer::normalize(''));
    }

    /** @dataProvider toInternationalDigitsProvider */
    public function test_to_international_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::toInternationalDigits($input));
    }

    public function toInternationalDigitsProvider(): array
    {
        return [
            'real user number with plus'     => ['+375333771416', '375333771416'],
            'already normalized from DB'     => ['375333771416', '375333771416'],
            '9 local digits like GS row'     => ['333771416', '375333771416'],
            '80 prefix'                      => ['80333771416', '375333771416'],
            'must not ltrim to 3751416'      => ['375333771416', '375333771416'],
        ];
    }

    /** @dataProvider toInternationalPlusProvider */
    public function test_to_international_plus(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::toInternationalPlus($input));
    }

    public function toInternationalPlusProvider(): array
    {
        return [
            'real user number'               => ['+375333771416', '+375333771416'],
            'from normalized DB value'       => ['375333771416', '+375333771416'],
            '9 local digits'                 => ['333771416', '+375333771416'],
        ];
    }

    public function test_to_international_digits_empty(): void
    {
        $this->assertSame('', PhoneNormalizer::toInternationalDigits(null));
        $this->assertSame('', PhoneNormalizer::toInternationalDigits(''));
    }

    public function test_ltrim_bug_regression(): void
    {
        $broken = '375' . ltrim('375333771416', '+375');
        $this->assertSame('3751416', $broken);
        $this->assertSame('375333771416', PhoneNormalizer::toInternationalDigits('375333771416'));
    }
}
