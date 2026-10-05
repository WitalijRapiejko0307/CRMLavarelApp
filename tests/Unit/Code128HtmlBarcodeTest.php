<?php

namespace Tests\Unit;

use App\Support\Code128HtmlBarcode;
use PHPUnit\Framework\TestCase;

class Code128HtmlBarcodeTest extends TestCase
{
    public function test_start_b_checksum_and_stop_for_s10_track(): void
    {
        $track = 'PC111111111BY';
        $codes = Code128HtmlBarcode::symbolCodes($track);

        $this->assertSame(104, $codes[0]);
        $this->assertSame(106, $codes[count($codes) - 1]);
        $this->assertCount(strlen($track) + 3, $codes);

        $checksum = 104;
        for ($i = 0; $i < strlen($track); $i++) {
            $checksum += (ord($track[$i]) - 32) * ($i + 1);
        }
        $this->assertSame($checksum % 103, $codes[count($codes) - 2]);
    }

    public function test_render_outputs_table_with_black_and_white_modules(): void
    {
        $html = Code128HtmlBarcode::render('PC111111111BY');

        $this->assertStringContainsString('class="barcode"', $html);
        $this->assertStringContainsString('background:#000', $html);
        $this->assertStringContainsString('background:#fff', $html);
        $this->assertGreaterThan(100, substr_count($html, '<td'));
    }
}
