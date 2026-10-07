<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the Apps Script Belpost tracking proxy (map once per run, batched direct search).
 */
class BelpostTrackingProxy
{
    public const BATCH_SIZE = 40;

    private const HTTP_TIMEOUT_SECONDS = 120;

    public function isConfigured(): bool
    {
        return $this->proxyUrl() !== '';
    }

    /**
     * @return array{
     *     ok: bool,
     *     notConfigured?: bool,
     *     map?: array<string, array{event: ?string, createdAt: ?string}>,
     *     error?: string
     * }
     */
    public function fetchMap(string $authToken): array
    {
        $url = $this->proxyUrl();

        if ($url === '') {
            return ['ok' => false, 'notConfigured' => true];
        }

        $result = $this->postProxy($url, [
            'secret'    => (string) env('BELPOST_TRACKING_PROXY_SECRET', ''),
            'authToken' => $authToken,
        ], 'fetchMap');

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        /** @var Response $response */
        $response = $result['response'];

        return [
            'ok'  => true,
            'map' => $this->parseMap($response->json()),
        ];
    }

    /**
     * @param  array<int, array{track: string, status: string}>  $items
     * @return array{
     *     ok: bool,
     *     notConfigured?: bool,
     *     results?: array<string, array{found: bool, event: ?string, createdAt: ?string, targetStatus: ?string}>,
     *     error?: string
     * }
     */
    public function directSearchBatch(string $authToken, array $items): array
    {
        $url = $this->proxyUrl();

        if ($url === '') {
            return ['ok' => false, 'notConfigured' => true];
        }

        if ($items === []) {
            return ['ok' => true, 'results' => []];
        }

        if (count($items) > self::BATCH_SIZE) {
            return ['ok' => false, 'error' => 'batch_too_large'];
        }

        $result = $this->postProxy($url, [
            'secret'    => (string) env('BELPOST_TRACKING_PROXY_SECRET', ''),
            'authToken' => $authToken,
            'items'     => $items,
        ], 'directSearchBatch');

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        /** @var Response $response */
        $response = $result['response'];

        return ['ok' => true, 'results' => $this->parseResults($response->json())];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, response?: Response, error?: string, notConfigured?: bool}
     */
    private function postProxy(string $url, array $payload, string $logContext): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning("BelpostTrackingProxy::{$logContext} transport error", [
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'transport'];
        }

        if ($response->status() === 403) {
            Log::warning("BelpostTrackingProxy::{$logContext} forbidden (bad secret)");

            return ['ok' => false, 'error' => 'forbidden'];
        }

        if ($response->status() === 502) {
            Log::warning("BelpostTrackingProxy::{$logContext} upstream map failure");

            return ['ok' => false, 'error' => 'upstream'];
        }

        if (!$response->successful()) {
            Log::warning("BelpostTrackingProxy::{$logContext} HTTP error", [
                'status' => $response->status(),
            ]);

            return ['ok' => false, 'error' => 'http_' . $response->status()];
        }

        return ['ok' => true, 'response' => $response];
    }

    /**
     * @param  mixed  $payload
     * @return array<string, array{event: ?string, createdAt: ?string}>
     */
    private function parseMap($payload): array
    {
        $out = [];

        if (!is_array($payload)) {
            return $out;
        }

        foreach ($payload['map'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $track = isset($row['track']) ? trim((string) $row['track']) : '';
            if ($track === '') {
                continue;
            }

            $out[$track] = [
                'event'     => $row['event'] ?? null,
                'createdAt' => $row['createdAt'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $payload
     * @return array<string, array{found: bool, event: ?string, createdAt: ?string, targetStatus: ?string}>
     */
    private function parseResults($payload): array
    {
        $out = [];

        if (!is_array($payload)) {
            return $out;
        }

        foreach ($payload['results'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $track = isset($row['track']) ? trim((string) $row['track']) : '';
            if ($track === '') {
                continue;
            }

            $out[$track] = [
                'found'        => (bool) ($row['found'] ?? false),
                'event'        => $row['event'] ?? null,
                'createdAt'    => $row['createdAt'] ?? null,
                'targetStatus' => $row['targetStatus'] ?? null,
            ];
        }

        return $out;
    }

    private function proxyUrl(): string
    {
        return trim((string) env('BELPOST_TRACKING_PROXY_URL', ''));
    }
}
