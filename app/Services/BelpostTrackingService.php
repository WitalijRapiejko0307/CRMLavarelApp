<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps Belpost tracking API.
 * Mirrors GAS loadBelpostMap() + directBelpostSearch() + parseBelpostTracking*.
 */
class BelpostTrackingService
{
    private const API_BASE    = 'https://api.belpost.by';
    private const TRACKING_V1 = '/api/v1/tracking';

    private const MAX_PAGES                 = 40;
    private const MAX_ATTEMPTS              = 3;
    private const DIRECT_SEARCH_ATTEMPTS    = 5;
    private const DIRECT_SEARCH_RETRY_SECONDS = 2;

    private string $authToken;

    public function __construct(string $authToken)
    {
        $this->authToken = $authToken;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Load all Belpost tracking pages into a lookup map.
     * Mirrors GAS loadBelpostMap(). Map stores last_event only (no steps[]).
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
     * Resolve tracking for one parcel: map when unambiguous, otherwise POST for steps[].
     * Mirrors GAS resolveBelpostTracking().
     *
     * @param  array|null $mapEntry  ['event' => ?, 'createdAt' => ?]
     * @return array|null  ['event' => ?, 'createdAt' => ?, 'targetStatus' => ?]
     */
    public function resolveTracking(string $trackNumber, ?array $mapEntry, string $currentStatus): ?array
    {
        if ($mapEntry && !self::needsBelpostStepsLookup($mapEntry, $currentStatus)) {
            return self::parseBelpostTrackingFromMapEntry($mapEntry);
        }

        $directResult = $this->directSearch($trackNumber);
        if ($directResult !== null) {
            return $directResult;
        }

        if ($mapEntry) {
            return self::parseBelpostTrackingFromMapEntry($mapEntry);
        }

        return null;
    }

    /**
     * Direct POST search for a single track number.
     * Mirrors GAS directBelpostSearch() — 5 attempts, 2s pause (GS uses 10s).
     *
     * @return array|null  ['event' => ?, 'createdAt' => ?, 'targetStatus' => ?]
     */
    public function directSearch(string $trackNumber): ?array
    {
        $response = null;

        for ($attempt = 0; $attempt < self::DIRECT_SEARCH_ATTEMPTS; $attempt++) {
            try {
                $resp = Http::timeout(30)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(self::API_BASE . self::TRACKING_V1, ['number' => $trackNumber]);

                if ($resp->successful()) {
                    $response = $resp;
                    break;
                }

                Log::warning("BelpostTrackingService::directSearch attempt {$attempt} HTTP {$resp->status()}", [
                    'track' => $trackNumber,
                ]);
            } catch (\Throwable $e) {
                Log::warning("BelpostTrackingService::directSearch attempt {$attempt} exception", [
                    'track' => $trackNumber,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::DIRECT_SEARCH_ATTEMPTS - 1) {
                $this->retryPause();
            }
        }

        if ($response === null) {
            Log::warning('BelpostTrackingService::directSearch failed after retries', [
                'track'    => $trackNumber,
                'attempts' => self::DIRECT_SEARCH_ATTEMPTS,
            ]);
            return null;
        }

        $data  = $response->json();
        $items = $data['data'] ?? [];

        if (empty($items)) {
            return null;
        }

        return self::parseBelpostTrackingItem($items[0]);
    }

    // ─── Parsers (pure, GS-equivalent) ─────────────────────────────────────

    public static function isBelpostReturnMarker(?array $step): bool
    {
        if (!$step) {
            return false;
        }

        $code  = $step['code'] ?? null;
        $event = $step['event'] ?? null;

        return $code === 25
            || $code === '25'
            || $event === 'Подготовлено для возврата'
            || $event === 'Вручено отправителю';
    }

    public static function hasBelpostReturnHistory(?array $steps): bool
    {
        if (!is_array($steps) || $steps === []) {
            return false;
        }

        foreach ($steps as $step) {
            if (self::isBelpostReturnMarker(is_array($step) ? $step : null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array|null $item  data[] element with steps[]
     * @return array{event: ?string, createdAt: ?string, targetStatus: ?string}
     */
    public static function parseBelpostTrackingItem(?array $item): array
    {
        $empty = ['event' => null, 'createdAt' => null, 'targetStatus' => null];

        if (!$item || !isset($item['steps']) || !is_array($item['steps']) || $item['steps'] === []) {
            return $empty;
        }

        $steps     = $item['steps'];
        $latest    = $steps[0];
        $event     = $latest['event'] ?? null;
        $createdAt = $latest['created_at'] ?? null;

        if ($event === 'Вручено отправителю') {
            return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => 'Возврат'];
        }

        if (self::hasBelpostReturnHistory($steps)) {
            return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => 'Возврат в пути'];
        }

        return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => null];
    }

    /**
     * @param  array|null $mapEntry  ['event' => ?, 'createdAt' => ?]
     * @return array{event: ?string, createdAt: ?string, targetStatus: ?string}
     */
    public static function parseBelpostTrackingFromMapEntry(?array $mapEntry): array
    {
        $empty = ['event' => null, 'createdAt' => null, 'targetStatus' => null];

        if (!$mapEntry || empty($mapEntry['event'])) {
            return $empty;
        }

        $event     = $mapEntry['event'];
        $createdAt = $mapEntry['createdAt'] ?? null;

        if ($event === 'Вручено отправителю') {
            return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => 'Возврат'];
        }

        if ($event === 'Подготовлено для возврата') {
            return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => 'Возврат в пути'];
        }

        return ['event' => $event, 'createdAt' => $createdAt, 'targetStatus' => null];
    }

    public static function isBelpostTransitEvent(?string $event): bool
    {
        return $event === 'Отправлено' || $event === 'Поступило в обработку';
    }

    /**
     * Map last_event is ambiguous: need steps[] via POST.
     */
    public static function needsBelpostStepsLookup(?array $mapEntry, string $currentStatus): bool
    {
        if (!$mapEntry || empty($mapEntry['event'])) {
            return false;
        }

        if ($mapEntry['event'] === 'Поступило в учреждение доставки') {
            return true;
        }

        if ($currentStatus === 'В отделении' && self::isBelpostTransitEvent($mapEntry['event'])) {
            return true;
        }

        return false;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function retryPause(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        sleep(self::DIRECT_SEARCH_RETRY_SECONDS);
    }

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
