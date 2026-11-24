<?php

namespace App\Services;

/**
 * Decides what the user gets: one merged PDF; if merging fails, the downloaded
 * PDFs as a ZIP (or the single PDF as is). Anything downloaded is always delivered.
 */
class EvropostLabelDelivery
{
    /** @var EvropostLabelMerger */
    private $merger;

    public function __construct(EvropostLabelMerger $merger)
    {
        $this->merger = $merger;
    }

    /**
     * @param  array<int, string>  $pdfs   order id => PDF binary
     * @param  array<int, string>  $names  order id => track number (file names in the ZIP)
     * @return array{body: string, content_type: string, filename: string, merge_failed: bool, kind: string}|array{error: string}
     */
    public function build(array $pdfs, array $names, EvropostLabelLogger $logger): array
    {
        ksort($pdfs);
        $stamp = date('Ymd-Hi');

        if (count($pdfs) === 1) {
            $id = array_key_first($pdfs);

            return $this->result(reset($pdfs), 'application/pdf', ($names[$id] ?? 'label') . '.pdf', false, 'single');
        }

        $started = microtime(true);
        try {
            $merged = $this->merger->merge($pdfs);
            $logger->log('merge', [
                'files'      => count($pdfs),
                'versions'   => array_map([EvropostLabelLogger::class, 'pdfVersion'], $pdfs),
                'pages'      => $merged['pages'],
                'page_sizes' => $merged['sizes'],
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'ok'         => true,
            ]);

            return $this->result($merged['binary'], 'application/pdf', "europost-labels-{$stamp}.pdf", false, 'pdf');
        } catch (\Throwable $e) {
            $logger->log('merge', [
                'files'      => count($pdfs),
                'versions'   => array_map([EvropostLabelLogger::class, 'pdfVersion'], $pdfs),
                'ok'         => false,
                'error'      => $e->getMessage(),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'samples'    => $logger->saveSamples($pdfs),
            ], 'warning');
        }

        return $this->zip($pdfs, $names, "europost-labels-{$stamp}.zip", $logger);
    }

    private function zip(array $pdfs, array $names, string $filename, EvropostLabelLogger $logger): array
    {
        if (!class_exists(\ZipArchive::class)) {
            $logger->log('delivery', ['kind' => 'zip', 'ok' => false, 'error' => 'zip extension missing'], 'error');

            return ['error' => 'Не удалось склеить ярлыки. Скачайте их по одному.'];
        }

        $path = tempnam(sys_get_temp_dir(), 'eplabels');
        $zip  = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            $logger->log('delivery', ['kind' => 'zip', 'ok' => false, 'error' => 'zip open failed'], 'error');

            return ['error' => 'Не удалось подготовить архив. Скачайте ярлыки по одному.'];
        }

        $used = [];
        foreach ($pdfs as $id => $pdf) {
            $base = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($names[$id] ?? '')) ?: ('order-' . $id);
            if (isset($used[$base])) {
                $base .= '-' . $id;
            }
            $used[$base] = true;
            $zip->addFromString($base . '.pdf', $pdf);
        }
        $zip->close();

        $body = (string) file_get_contents($path);
        @unlink($path);

        return $this->result($body, 'application/zip', $filename, true, 'zip');
    }

    private function result(string $body, string $type, string $filename, bool $mergeFailed, string $kind): array
    {
        return [
            'body'         => $body,
            'content_type' => $type,
            'filename'     => $filename,
            'merge_failed' => $mergeFailed,
            'kind'         => $kind,
        ];
    }
}
