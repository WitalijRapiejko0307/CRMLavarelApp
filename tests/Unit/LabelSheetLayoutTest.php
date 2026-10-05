<?php

namespace Tests\Unit;

use App\Support\LabelSheetLayout;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LabelSheetLayoutTest extends TestCase
{
    /** MailBatch::LABEL_SIZES — kept here so this test stays a pure PHPUnit case. */
    private const LABEL_SIZES = ['210x150', '150x100', '120x80'];

    public function test_all_standard_sizes_fit_at_least_one_per_page(): void
    {
        foreach (self::LABEL_SIZES as $size) {
            $layout = LabelSheetLayout::forLabelSize($size);
            $this->assertGreaterThanOrEqual(1, $layout->perPage(), $size);
            $this->assertFalse($layout->rotated(), $size);
        }
    }

    public function test_150x100_is_two_per_a4(): void
    {
        $layout = LabelSheetLayout::forLabelSize('150x100');
        $this->assertSame(2, $layout->perPage());
        $this->assertSame(1, $layout->cols());
        $this->assertSame(2, $layout->rows());
        $this->assertFalse($layout->rotated());
        $this->assertTrue($layout->usesA4());
        $this->assertTrue($layout->drawsOuterBorder());
        $this->assertSame(150.0, $layout->labelWidthMm());
        $this->assertSame(100.0, $layout->labelHeightMm());
        $this->assertSame(100.0, $layout->printLabelHeightMm());
        $this->assertSame(5.0, $layout->marginMm());
        $this->assertSame(2.0, $layout->gapMm());
        $this->assertSame(210.0, $layout->sheetWidthMm());
        $this->assertSame(297.0, $layout->sheetHeightMm());
    }

    public function test_120x80_is_one_thermal_page(): void
    {
        $layout = LabelSheetLayout::forLabelSize('120x80');
        $this->assertSame(1, $layout->perPage());
        $this->assertFalse($layout->rotated());
        $this->assertFalse($layout->usesA4());
        $this->assertFalse($layout->drawsOuterBorder());
        $this->assertSame(1, $layout->cols());
        $this->assertSame(1, $layout->rows());
        $this->assertSame(120.0, $layout->labelWidthMm());
        $this->assertSame(80.0, $layout->labelHeightMm());
        $this->assertSame(120.0, $layout->sheetWidthMm());
        $this->assertSame(80.0, $layout->sheetHeightMm());
        $this->assertSame(0.0, $layout->marginMm());
        $this->assertSame(0.0, $layout->gapMm());
        $this->assertSame(3, $layout->pageCount(3));
        $css = $layout->css();
        $this->assertStringContainsString('size: 120mm 80mm', $css);
        $this->assertStringNotContainsString('transform', $css);
        $this->assertStringNotContainsString('scale', $css);
    }

    public function test_210x150_is_two_per_a4_unrotated(): void
    {
        $layout = LabelSheetLayout::forLabelSize('210x150');
        $this->assertSame(2, $layout->perPage());
        $this->assertFalse($layout->rotated());
        $this->assertTrue($layout->usesA4());
        $this->assertTrue($layout->drawsOuterBorder());
        $this->assertSame(1, $layout->cols());
        $this->assertSame(2, $layout->rows());
        $this->assertSame(210.0, $layout->labelWidthMm());
        $this->assertSame(150.0, $layout->labelHeightMm());
        $this->assertSame(0.0, $layout->marginMm());
        $this->assertSame(0.0, $layout->gapMm());
        $this->assertSame(146.0, $layout->printLabelHeightMm());
        $this->assertSame(2, $layout->pageCount(3));
        $this->assertSame(2, $layout->slotsOnPage(0, 3));
        $this->assertSame(1, $layout->slotsOnPage(1, 3));
        $css = $layout->css();
        $this->assertStringContainsString('@page { size: A4; margin: 0; }', $css);
        $this->assertStringContainsString('width: 210mm', $css);
        $this->assertStringContainsString('height: 146mm', $css);
        $this->assertStringNotContainsString('transform', $css);
        $this->assertStringNotContainsString('scale', $css);
    }

    public function test_three_150x100_labels_span_two_pages(): void
    {
        $layout = LabelSheetLayout::forLabelSize('150x100');

        $this->assertSame(2, $layout->pageCount(3));
        $this->assertSame(2, $layout->slotsOnPage(0, 3));
        $this->assertSame(1, $layout->slotsOnPage(1, 3));
        $this->assertSame(0, $layout->slotsOnPage(2, 3));
    }

    /**
     * @dataProvider standardSizesProvider
     */
    public function test_grid_fits_inner_page_with_margin_and_gap(string $size): void
    {
        $layout = LabelSheetLayout::forLabelSize($size);
        $margin = $layout->marginMm();
        $gap    = $layout->gapMm();

        $innerW = $layout->sheetWidthMm() - 2 * $margin;
        $innerH = $layout->sheetHeightMm() - 2 * $margin;

        $this->assertGreaterThan(0, $innerW);
        $this->assertGreaterThan(0, $innerH);
        $this->assertLessThanOrEqual($innerW, $layout->labelWidthMm());
        $this->assertLessThanOrEqual($innerH, $layout->printLabelHeightMm());

        $usedW = $layout->cols() * $layout->labelWidthMm()
            + max(0, $layout->cols() - 1) * $gap;
        $usedH = $layout->rows() * $layout->printLabelHeightMm()
            + max(0, $layout->rows() - 1) * $gap;

        $this->assertGreaterThanOrEqual(0, $innerW - $usedW, $size . ' last-col leftover');
        $this->assertGreaterThanOrEqual(0, $innerH - $usedH, $size . ' last-row leftover');

        $spaceForLastCol = $innerW - max(0, $layout->cols() - 1) * ($layout->labelWidthMm() + $gap);
        $spaceForLastRow = $innerH - max(0, $layout->rows() - 1) * ($layout->printLabelHeightMm() + $gap);
        $this->assertGreaterThanOrEqual($layout->labelWidthMm(), $spaceForLastCol, $size . ' last col');
        $this->assertGreaterThanOrEqual($layout->printLabelHeightMm(), $spaceForLastRow, $size . ' last row');
    }

    public function standardSizesProvider(): array
    {
        return [
            '210x150' => ['210x150'],
            '150x100' => ['150x100'],
            '120x80'  => ['120x80'],
        ];
    }

    public function test_css_uses_a4_and_avoids_scale(): void
    {
        $css = LabelSheetLayout::forLabelSize('150x100')->css();

        $this->assertStringContainsString('@page { size: A4; margin: 0; }', $css);
        $this->assertStringContainsString('page-break-inside: avoid', $css);
        $this->assertStringContainsString('width: 150mm', $css);
        $this->assertStringContainsString('height: 100mm', $css);
        $this->assertStringNotContainsString('transform', $css);
        $this->assertStringNotContainsString('scale', $css);
    }

    public function test_oversized_label_uses_custom_page_size(): void
    {
        $layout = LabelSheetLayout::forLabelSize('400x300');

        $this->assertSame(1, $layout->perPage());
        $this->assertFalse($layout->usesA4());
        $this->assertFalse($layout->rotated());
        $this->assertSame(400.0, $layout->sheetWidthMm());
        $this->assertSame(300.0, $layout->sheetHeightMm());
        $this->assertSame(1, $layout->pageCount(1));
        $this->assertStringContainsString('size: 400mm 300mm', $layout->css());
    }

    public function test_invalid_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabelSheetLayout::forLabelSize('not-a-size');
    }
}
