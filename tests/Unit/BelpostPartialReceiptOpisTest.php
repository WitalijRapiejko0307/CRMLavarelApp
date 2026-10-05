<?php

namespace Tests\Unit;

use App\Services\BelpostPartialReceiptOpisBuilder;
use App\Support\BelpostRublesInWords;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BelpostPartialReceiptOpisTest extends TestCase
{
    public function test_rubles_in_words_zero_and_eighty(): void
    {
        $this->assertSame('ноль рублей 00 копеек', BelpostRublesInWords::format(0, 0));
        $this->assertSame('восемьдесят рублей 00 копеек', BelpostRublesInWords::format(80, 0));
    }

    public function test_rubles_in_words_singular_and_few(): void
    {
        $this->assertSame('один рубль 00 копеек', BelpostRublesInWords::format(1, 0));
        $this->assertSame('два рубля 00 копеек', BelpostRublesInWords::format(2, 0));
        $this->assertSame('пять рублей 00 копеек', BelpostRublesInWords::format(5, 0));
    }

    public function test_docx_contains_track_and_product_names(): void
    {
        $track = 'PC350173404BY';
        $builder = new BelpostPartialReceiptOpisBuilder();
        $binary = $builder->build([
            'track'          => $track,
            'recipient_name' => 'Иванов Иван Иванович',
            'sender_name'    => 'ИП Тестов Тест Тестович',
            'cod_rubles'     => 80,
            'lines'          => [
                [
                    'article'  => 'Футболка синяя',
                    'name'     => 'Футболка синяя',
                    'quantity' => 1,
                    'price'    => 40.0,
                ],
                [
                    'article'  => 'Шорты чёрные',
                    'name'     => 'Шорты чёрные',
                    'quantity' => 1,
                    'price'    => 40.0,
                ],
            ],
        ]);

        $this->assertNotSame('', $binary);
        $this->assertSame('PK', substr($binary, 0, 2));

        $tmp = tempnam(sys_get_temp_dir(), 'opis_test_');
        $this->assertNotFalse($tmp);
        $path = $tmp . '.docx';
        @unlink($tmp);
        file_put_contents($path, $binary);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertIsString($xml);
        $this->assertStringContainsString($track, $xml);
        $this->assertStringContainsString('Футболка синяя', $xml);
        $this->assertStringContainsString('Шорты чёрные', $xml);
        $this->assertStringContainsString('Экземпляр РУП «Белпочта»', $xml);
        $this->assertStringContainsString('Экземпляр отправителя (интернет-магазина)', $xml);
        $this->assertStringContainsString('восемьдесят рублей 00 копеек', $xml);
    }
}
