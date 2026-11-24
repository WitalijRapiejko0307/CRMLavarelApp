<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Silent diagnostics: calls the batch label endpoint once in a while and logs only
 * response metadata (never the content). Runs after the user response is sent.
 */
class EvropostLabelProbe
{
    private const BASE = 'https://api-kassa.evropochta.by';

    /**
     * @param  array<int, string>  $tracks
     */
    public function run(string $token, array $tracks, string $format, EvropostLabelLogger $logger, int $tenantId): void
    {
        if (!filter_var(env('EVROPOST_LABEL_PROBE', true), FILTER_VALIDATE_BOOLEAN) || !$tracks) {
            return;
        }

        try {
            if (!Cache::add('europost_label_probe:' . $tenantId, 1, 60)) {
                return;
            }

            $path    = $format === EvropostService::LABEL_FORMAT_THERMAL ? '/api/external/label/thermals' : '/api/external/label/addresses';
            $started = microtime(true);

            $response = Http::timeout(30)
                ->withHeaders(['Token' => $token, 'Content-Type' => 'application/json'])
                ->post(self::BASE . $path, ['numbers' => array_values($tracks)]);

            $body = (string) $response->body();
            $ctx  = [
                'path'         => $path,
                'tracks'       => count($tracks),
                'status'       => $response->status(),
                'content_type' => (string) $response->header('Content-Type'),
                'size'         => strlen($body),
                'head'         => bin2hex(substr($body, 0, 8)),
                'pdf_version'  => EvropostLabelLogger::pdfVersion($body),
                'elapsed_ms'   => (int) round((microtime(true) - $started) * 1000),
            ];

            if ($response->status() === 422) {
                $ctx['error_body'] = mb_substr($body, 0, 1000);
            }

            $logger->log('probe', $ctx);
        } catch (\Throwable $e) {
            $logger->log('probe', ['ok' => false, 'error' => $e->getMessage()], 'warning');
        }
    }
}
