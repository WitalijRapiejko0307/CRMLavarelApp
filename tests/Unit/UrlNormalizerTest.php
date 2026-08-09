<?php

namespace Tests\Unit;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    /** @dataProvider normalizeProvider */
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, UrlNormalizer::normalize($input));
    }

    public function normalizeProvider(): array
    {
        return [
            'adds https to bare domain'     => ['example.com/page', 'https://example.com/page'],
            'preserves https'                 => ['https://example.com', 'https://example.com'],
            'preserves http'                  => ['http://example.com', 'http://example.com'],
            'trims whitespace'                => ['  example.com  ', 'https://example.com'],
            'empty string becomes null'       => ['', null],
            'null stays null'                 => [null, null],
            'whitespace only becomes null'    => ['   ', null],
        ];
    }
}
