<?php

namespace Tests\Unit;

use App\Services\EvropostLabelDelivery;
use App\Services\EvropostLabelLogger;
use App\Services\EvropostLabelMerger;
use PHPUnit\Framework\TestCase;

class EvropostLabelDeliveryTest extends TestCase
{
    private function pdf(float $w, float $h, string $text): string
    {
        $p = new \FPDF('P', 'mm', [$w, $h]);
        $p->AddPage();
        $p->SetFont('Arial', '', 12);
        $p->Cell(0, 10, $text);

        return $p->Output('S');
    }

    private function delivery(): EvropostLabelDelivery
    {
        return new EvropostLabelDelivery(new EvropostLabelMerger());
    }

    public function test_pdfs_are_merged_keeping_page_sizes(): void
    {
        $merged = (new EvropostLabelMerger())->merge([
            1 => $this->pdf(100, 100, 'A'),
            2 => $this->pdf(148, 210, 'B'),
        ]);

        $this->assertSame(2, $merged['pages']);
        $this->assertSame([[100.0, 100.0], [148.0, 210.0]], $merged['sizes']);
        $this->assertStringStartsWith('%PDF', $merged['binary']);
    }

    public function test_merge_failure_names_the_broken_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/file "2"/');

        (new EvropostLabelMerger())->merge([1 => $this->pdf(100, 100, 'A'), 2 => 'not a pdf']);
    }

    public function test_delivery_returns_merged_pdf(): void
    {
        $r = $this->delivery()->build(
            [2 => $this->pdf(100, 100, 'B'), 1 => $this->pdf(100, 100, 'A')],
            [1 => 'BY1', 2 => 'BY2'],
            new EvropostLabelLogger(1)
        );

        $this->assertSame('pdf', $r['kind']);
        $this->assertSame('application/pdf', $r['content_type']);
        $this->assertFalse($r['merge_failed']);
    }

    public function test_delivery_falls_back_to_zip_when_merge_fails(): void
    {
        $r = $this->delivery()->build(
            [1 => $this->pdf(100, 100, 'A'), 2 => 'broken'],
            [1 => 'BY1', 2 => 'BY2'],
            new EvropostLabelLogger(1)
        );

        $this->assertSame('zip', $r['kind']);
        $this->assertSame('application/zip', $r['content_type']);
        $this->assertTrue($r['merge_failed']);

        $path = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($path, $r['body']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('BY1.pdf'));
        $this->assertNotFalse($zip->locateName('BY2.pdf'));
        $zip->close();
        unlink($path);
    }

    public function test_single_pdf_is_returned_as_is(): void
    {
        $pdf = $this->pdf(100, 100, 'A');
        $r   = $this->delivery()->build([7 => $pdf], [7 => 'BY7'], new EvropostLabelLogger(1));

        $this->assertSame('single', $r['kind']);
        $this->assertSame($pdf, $r['body']);
        $this->assertSame('BY7.pdf', $r['filename']);
    }

    public function test_pdf_version_is_read_from_header(): void
    {
        $this->assertSame('1.3', EvropostLabelLogger::pdfVersion('%PDF-1.3 xx'));
        $this->assertNull(EvropostLabelLogger::pdfVersion('PK zip'));
    }
}
