<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Diagnostics for Europochta label printing: one log channel (europost_labels),
 * all entries of one request share request_id. PDF content is never logged
 * (it contains recipient names and phones); failed merges may keep a few
 * private samples in storage/app/europost-label-debug (purged after 14 days).
 */
class EvropostLabelLogger
{
    private const SAMPLES_DIR      = 'europost-label-debug';
    private const SAMPLES_PER_DAY  = 5;

    /** @var string */
    private $requestId;

    /** @var int */
    private $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId  = $tenantId;
        $this->requestId = (string) Str::uuid();
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function log(string $stage, array $context = [], string $level = 'info'): void
    {
        try {
            Log::channel('europost_labels')->{$level}('europost_labels.' . $stage, array_merge([
                'request_id' => $this->requestId,
                'tenant_id'  => $this->tenantId,
            ], $context));
        } catch (\Throwable $e) {
            // logging must never break printing
        }
    }

    /**
     * PDF version string from the header, e.g. "1.4"; null if not a PDF.
     */
    public static function pdfVersion(string $binary): ?string
    {
        return preg_match('/^%PDF-(\d\.\d)/', substr($binary, 0, 16), $m) ? $m[1] : null;
    }

    /**
     * Keep a failed source PDF for diagnosis (capped per tenant per day).
     *
     * @param  array<int, string>  $pdfs
     */
    public function saveSamples(array $pdfs): int
    {
        if (!filter_var(env('EVROPOST_LABEL_DEBUG_SAVE', true), FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }

        $saved = 0;
        try {
            $key = 'europost_label_samples:' . $this->tenantId . ':' . date('Ymd');
            Cache::add($key, 0, 86400);

            foreach ($pdfs as $i => $pdf) {
                if ((int) Cache::get($key, 0) >= self::SAMPLES_PER_DAY) {
                    break;
                }
                $dir = storage_path('app/' . self::SAMPLES_DIR . '/' . $this->requestId);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (@file_put_contents($dir . '/' . $i . '.pdf', $pdf) !== false) {
                    Cache::increment($key);
                    $saved++;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $saved;
    }

    public static function purgeSamples(int $days): void
    {
        $root = storage_path('app/' . self::SAMPLES_DIR);
        if (!is_dir($root)) {
            return;
        }
        $limit = time() - $days * 86400;

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $limit) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }
    }
}
