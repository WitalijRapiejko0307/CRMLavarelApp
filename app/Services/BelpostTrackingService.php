<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps Belpost tracking API.
 * Mirrors GAS loadBelpostMap() + directBelpostSearch().
 */
class BelpostTrackingService
{
    private const API_BASE    = 'https://api.belpost.by';
    private const TRACKING_V1 = '/api/v1/tracking';

    private const MAX_PAGES    = 40;
    private const MAX_ATTEMPTS = 3;

    private string $authToken;

    public function __construct(string $authToken)
    {
        $this->authToken = $authToken;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Load all Belpost tracking pages into a lookup map.
     * Mirrors GAS loadBelpostMap().
     *
     * @return array  trackNumber => ['event' => string|null, 'createdAt' => string|null]
     */
    public function loadMap(): array
    {
        $map         = [];
        $currentPage = 1;
        $totalPages  = 1;

        while ($currentPage <= min(self::MAX_PAGES, $totalPages)) {
            $data = $this->fetchPage($currentPage);

            if ($data === null) {
                $currentPage++;
                continue;
            }

            if (isset($data['last_page']) && (int) $data['last_page'] > $totalPages) {
                $totalPages = (int) $data['last_page'];
            }

            foreach ($data['data'] ?? [] as $item) {
                $trackNum = isset($item['number']) ? trim((string) $item['number']) : null;

                if (!$trackNum) {
                    continue;
                }

                $lastEvent      = $item['last_event'] ?? null;
                $map[$trackNum] = [
                    'event'     => $lastEvent['event']      ?? null,
                    'createdAt' => $lastEvent['created_at'] ?? null,
                ];
            }

            $currentPage++;
        }

        Log::info('BelpostTrackingService::loadMap', [
            'tracks' => count($map),
            'pages'  => $currentPage - 1,
        ]);

        return $map;
    }

    /**
     * Direct POST search for a single track number.
     * Used when the track is not found in the paginated map.
     * Mirrors GAS directBelpostSearch().
     *
     * @return array|null  ['event' => string|null, 'createdAt' => string|null]  or null on failure
     */
    public function directSearch(string $trackNumber): ?array
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::API_BASE . self::TRACKING_V1, ['number' => $trackNumber]);

            if ($response->successful()) {
                break;
            }

            Log::warning("BelpostTrackingService::directSearch attempt {$attempt} HTTP {$response->status()}", [
                'track' => $trackNumber,
            ]);
        }

        if (!isset($response) || !$response->successful()) {
            return null;
        }

        $data  = $response->json();
        $items = $data['data'] ?? [];

        if (empty($items)) {
            return null;
        }

        $steps = $items[0]['steps'] ?? [];

        if (empty($steps)) {
            return ['event' => null, 'createdAt' => null];
        }

        return [
            'event'     => $steps[0]['event']      ?? null,
            'createdAt' => $steps[0]['created_at'] ?? null,
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function fetchPage(int $page): ?array
    {
        $url = self::API_BASE . self::TRACKING_V1 . '?page=' . $page;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Cache-Control' => 'no-cache',
                    'Content-Type'  => 'application/json',
                    'Authorization' => $this->authToken,
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("BelpostTrackingService::fetchPage {$page} attempt {$attempt} HTTP {$response->status()}");
        }

        return null;
    }
}
