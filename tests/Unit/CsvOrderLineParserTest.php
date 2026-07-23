<?php

namespace Tests\Unit;

use App\Support\CsvOrderLineParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CsvOrderLineParserTest extends TestCase
{
    public function test_parses_multiple_goods_with_space_separated_qty_and_prices(): void
    {
        $result = CsvOrderLineParser::parse(
            'Триммер Super, Культиватор Pro',
            '3 3',
            '135 123',
        );

        $this->assertSame(['Триммер Super', 'Культиватор Pro'], $result['goods']);
        $this->assertSame([3, 3], $result['quantities']);
        $this->assertSame([135, 123], $result['prices']);
    }

    public function test_single_good_with_semicolon_in_name(): void
    {
        $result = CsvOrderLineParser::parse(
            'Сучкорез Makita 6"; 8"',
            '1',
            '260,55',
        );

        $this->assertSame(['Сучкорез Makita 6"; 8"'], $result['goods']);
        $this->assertSame([1], $result['quantities']);
        $this->assertSame([260.55], $result['prices']);
    }

    public function test_decimal_comma_in_price(): void
    {
        $result = CsvOrderLineParser::parse(
            'Товар А',
            '2',
            '260,55',
        );

        $this->assertSame([260.55], $result['prices']);
    }

    public function test_mismatch_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Количество товаров (2)');

        CsvOrderLineParser::parse('Товар А, Товар Б', '1', '100');
    }

    public function test_empty_qty_defaults_to_one_for_single_good(): void
    {
        $result = CsvOrderLineParser::parse('Товар А', null, '100');

        $this->assertSame(['Товар А'], $result['goods']);
        $this->assertSame([1], $result['quantities']);
        $this->assertSame([100], $result['prices']);
    }

    public function test_empty_price_defaults_to_zero_for_single_good(): void
    {
        $result = CsvOrderLineParser::parse('Товар А', '2', null);

        $this->assertSame([2], $result['quantities']);
        $this->assertSame([0], $result['prices']);
    }
}
