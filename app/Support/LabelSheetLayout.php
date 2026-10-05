<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * N-up geometry for Belpost blanks.
 *
 * Official MailBatch::LABEL_SIZES use explicit profiles (no auto-rotate, no max-n).
 * Unknown sizes keep the A4 maximizer, or a custom page when nothing fits.
 *
 * Never scale a label: blades use the mm sizes from css(), not transform:scale.
 *
 * 210×150 on A4: two 150 mm blanks need 300 mm vs A4 297 mm. printLabelHeightMm()
 * is 146 mm so Dompdf keeps both on one page (decision A, ~3 mm clip plus renderer slack).
 */
class LabelSheetLayout
{
    public const SHEET_WIDTH_MM  = 210.0;
    public const SHEET_HEIGHT_MM = 297.0;
    public const MARGIN_MM       = 4.0;
    public const GAP_MM          = 2.0;

    /**
     * Official Belpost blank sizes. Keys match MailBatch::LABEL_SIZES.
     *
     * @var array<string, array{label_w: float, label_h: float, cols: int, rows: int, rotated: bool, uses_a4: bool, margin: float, gap: float}>
     */
    public const PROFILES = [
        '210x150' => [
            'label_w' => 210.0,
            'label_h' => 150.0,
            'cols'    => 1,
            'rows'    => 2,
            'rotated' => false,
            'uses_a4' => true,
            'margin'  => 0.0,
            'gap'     => 0.0,
        ],
        '150x100' => [
            'label_w' => 150.0,
            'label_h' => 100.0,
            'cols'    => 1,
            'rows'    => 2,
            'rotated' => false,
            'uses_a4' => true,
            'margin'  => 5.0,
            'gap'     => 2.0,
        ],
        '120x80' => [
            'label_w' => 120.0,
            'label_h' => 80.0,
            'cols'    => 1,
            'rows'    => 1,
            'rotated' => false,
            'uses_a4' => false,
            'margin'  => 0.0,
            'gap'     => 0.0,
        ],
    ];

    private string $sizeKey;
    private float $labelWidthMm;
    private float $labelHeightMm;
    private int $cols;
    private int $rows;
    private bool $rotated;
    private bool $usesA4;
    private float $marginMm;
    private float $gapMm;

    private function __construct(
        string $sizeKey,
        float $labelWidthMm,
        float $labelHeightMm,
        int $cols,
        int $rows,
        bool $rotated,
        bool $usesA4,
        float $marginMm,
        float $gapMm
    ) {
        $this->sizeKey       = $sizeKey;
        $this->labelWidthMm  = $labelWidthMm;
        $this->labelHeightMm = $labelHeightMm;
        $this->cols          = $cols;
        $this->rows          = $rows;
        $this->rotated       = $rotated;
        $this->usesA4        = $usesA4;
        $this->marginMm      = $marginMm;
        $this->gapMm         = $gapMm;
    }

    /**
     * @param  string  $wxh  e.g. "150x100" (width x height in mm)
     */
    public static function forLabelSize(string $wxh): self
    {
        [$inputW, $inputH] = self::parseSize($wxh);
        $key = strtolower(trim($wxh));

        if (isset(self::PROFILES[$key])) {
            $p = self::PROFILES[$key];

            return new self(
                $key,
                $p['label_w'],
                $p['label_h'],
                $p['cols'],
                $p['rows'],
                $p['rotated'],
                $p['uses_a4'],
                $p['margin'],
                $p['gap']
            );
        }

        $asIs       = self::countOnA4($inputW, $inputH);
        $rotated    = self::countOnA4($inputH, $inputW);
        $useRotated = $rotated['n'] > $asIs['n'];
        $chosen     = $useRotated ? $rotated : $asIs;

        if ($chosen['n'] === 0) {
            return new self($key, $inputW, $inputH, 1, 1, false, false, 0.0, 0.0);
        }

        return new self(
            $key,
            $useRotated ? $inputH : $inputW,
            $useRotated ? $inputW : $inputH,
            $chosen['cols'],
            $chosen['rows'],
            $useRotated,
            true,
            self::MARGIN_MM,
            self::GAP_MM
        );
    }

    public function sizeKey(): string
    {
        return $this->sizeKey;
    }

    public function sheetWidthMm(): float
    {
        return $this->usesA4 ? self::SHEET_WIDTH_MM : $this->labelWidthMm;
    }

    public function sheetHeightMm(): float
    {
        return $this->usesA4 ? self::SHEET_HEIGHT_MM : $this->labelHeightMm;
    }

    public function labelWidthMm(): float
    {
        return $this->labelWidthMm;
    }

    public function labelHeightMm(): float
    {
        return $this->labelHeightMm;
    }

    /**
     * Height written to CSS. For 210×150 two-up on A4 this is 148.5 mm so both
     * blanks stay on one page (150+150=300 vs 297).
     */
    public function printLabelHeightMm(): float
    {
        if ($this->sizeKey === '210x150') {
            // 150+150=300 vs A4 297, plus Dompdf border/table slack.
            // 146×2=292 mm keeps both blanks on one page (decision A).
            return 146.0;
        }

        return $this->labelHeightMm;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function perPage(): int
    {
        return max(1, $this->cols * $this->rows);
    }

    public function rotated(): bool
    {
        return $this->rotated;
    }

    public function usesA4(): bool
    {
        return $this->usesA4;
    }

    public function drawsOuterBorder(): bool
    {
        return $this->usesA4;
    }

    public function marginMm(): float
    {
        return $this->marginMm;
    }

    public function gapMm(): float
    {
        return $this->gapMm;
    }

    public function pageCount(int $labelCount): int
    {
        if ($labelCount <= 0) {
            return 0;
        }

        return (int) ceil($labelCount / $this->perPage());
    }

    public function slotsOnPage(int $pageIndexZeroBased, int $labelCount): int
    {
        if ($pageIndexZeroBased < 0 || $labelCount <= 0) {
            return 0;
        }

        $start = $pageIndexZeroBased * $this->perPage();
        if ($start >= $labelCount) {
            return 0;
        }

        return min($this->perPage(), $labelCount - $start);
    }

    /**
     * CSS for PDF blades. Labels are fixed-mm blocks; do not scale.
     */
    public function css(): string
    {
        $pageSize = $this->usesA4
            ? 'A4'
            : $this->formatMm($this->sheetWidthMm()) . ' ' . $this->formatMm($this->sheetHeightMm());

        $margin = $this->formatMm($this->marginMm);
        $width  = $this->formatMm($this->labelWidthMm);
        $height = $this->formatMm($this->printLabelHeightMm());

        return '@page { size: ' . $pageSize . '; margin: 0; }' . "\n"
            . '.label-sheet { padding: ' . $margin . '; box-sizing: border-box; }' . "\n"
            . '.label {' . "\n"
            . '  width: ' . $width . ';' . "\n"
            . '  height: ' . $height . ';' . "\n"
            . '  page-break-inside: avoid;' . "\n"
            . '  break-inside: avoid;' . "\n"
            . '  box-sizing: border-box;' . "\n"
            . '}';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'size_key'              => $this->sizeKey,
            'sheet_width_mm'        => $this->sheetWidthMm(),
            'sheet_height_mm'       => $this->sheetHeightMm(),
            'label_width_mm'        => $this->labelWidthMm,
            'label_height_mm'       => $this->labelHeightMm,
            'print_label_height_mm' => $this->printLabelHeightMm(),
            'cols'                  => $this->cols,
            'rows'                  => $this->rows,
            'per_page'              => $this->perPage(),
            'rotated'               => $this->rotated,
            'uses_a4'               => $this->usesA4,
            'margin_mm'             => $this->marginMm,
            'gap_mm'                => $this->gapMm,
            'css'                   => $this->css(),
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function parseSize(string $wxh): array
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/i', trim($wxh), $m)) {
            throw new InvalidArgumentException('Label size must look like "150x100", got: ' . $wxh);
        }

        $width  = (float) $m[1];
        $height = (float) $m[2];

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Label size must be positive, got: ' . $wxh);
        }

        return [$width, $height];
    }

    /**
     * cols = floor((sheetW - 2*margin + gap) / (labelW + gap))
     * rows = floor((sheetH - 2*margin + gap) / (labelH + gap))
     *
     * @return array{cols: int, rows: int, n: int}
     */
    private static function countOnA4(float $labelW, float $labelH): array
    {
        $cols = (int) floor(
            (self::SHEET_WIDTH_MM - 2 * self::MARGIN_MM + self::GAP_MM) / ($labelW + self::GAP_MM)
        );
        $rows = (int) floor(
            (self::SHEET_HEIGHT_MM - 2 * self::MARGIN_MM + self::GAP_MM) / ($labelH + self::GAP_MM)
        );
        $cols = max(0, $cols);
        $rows = max(0, $rows);

        return [
            'cols' => $cols,
            'rows' => $rows,
            'n'    => $cols * $rows,
        ];
    }

    private function formatMm(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return $formatted . 'mm';
    }
}
