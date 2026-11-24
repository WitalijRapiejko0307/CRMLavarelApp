<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Merges label PDFs into one file keeping each source page size (1 label = 1 page).
 */
class EvropostLabelMerger
{
    /**
     * @param  array<int|string, string>  $pdfs  key => PDF binary (key identifies the failing file)
     * @return array{binary: string, pages: int, sizes: array}
     * @throws RuntimeException  message holds the failing key and the FPDI error
     */
    public function merge(array $pdfs): array
    {
        $fpdi  = new Fpdi();
        $pages = 0;
        $sizes = [];

        foreach ($pdfs as $key => $pdf) {
            try {
                $count = $fpdi->setSourceFile(StreamReader::createByString($pdf));

                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $fpdi->importPage($i);
                    $size = $fpdi->getTemplateSize($tpl);
                    $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $fpdi->useTemplate($tpl);
                    $pages++;
                    $sizes[] = [round($size['width']), round($size['height'])];
                }
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'merge failed on file "' . $key . '": ' . get_class($e) . ': ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return ['binary' => $fpdi->Output('S'), 'pages' => $pages, 'sizes' => $sizes];
    }
}
