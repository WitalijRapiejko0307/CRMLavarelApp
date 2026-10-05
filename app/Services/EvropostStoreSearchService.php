<?php

namespace App\Services;

use App\Exceptions\EvropostStoreDirectoryException;
use Illuminate\Support\Facades\Cache;

class EvropostStoreSearchService
{
    public const CACHE_KEY = 'europochta:stores:type1';
    public const CACHE_TTL = 86400; // 24 hours, instance-wide

    private const RESULT_LIMIT = 30;

    private EvropostService $evropost;

    public function __construct(EvropostService $evropost)
    {
        $this->evropost = $evropost;
    }

    /**
     * Filter the cached OPS directory. Fetches from Europochta on miss (tenant token).
     *
     * @return array{ok: bool, items?: array, error?: string, status?: int, message?: string}
     */
    public function search(string $q, int $tenantId): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['ok' => true, 'items' => []];
        }

        $directory = $this->directory($tenantId);
        if (empty($directory['ok'])) {
            return $directory;
        }

        return [
            'ok'    => true,
            'items' => $this->filter($directory['stores'], $q),
        ];
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{ok: bool, stores?: array, error?: string, status?: int, message?: string}
     */
    private function directory(int $tenantId): array
    {
        try {
            $stores = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($tenantId) {
                $result = $this->evropost->listStores($tenantId, 1);
                if (empty($result['ok'])) {
                    throw new EvropostStoreDirectoryException($result);
                }

                return $result['stores'];
            });
        } catch (EvropostStoreDirectoryException $e) {
            return $e->payload;
        }

        if (!is_array($stores)) {
            $this->forgetCache();

            return [
                'ok'      => false,
                'error'   => 'api_error',
                'status'  => 0,
                'message' => 'Ошибка получения списка ОПС: неожиданный ответ',
            ];
        }

        return ['ok' => true, 'stores' => $stores];
    }

    /**
     * @param  array  $stores
     * @return array<int, array<string, mixed>>
     */
    private function filter(array $stores, string $q): array
    {
        $matched = ctype_digit($q)
            ? $this->filterByOpsNumber($stores, $q)
            : $this->filterByText($stores, $q);

        $items = [];
        foreach ($matched as $store) {
            $items[] = $this->formatItem($store);
            if (count($items) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * Exact ops_number first, then prefix (777 → 777, 7771, …).
     *
     * @param  array  $stores
     * @return array
     */
    private function filterByOpsNumber(array $stores, string $q): array
    {
        $exact  = [];
        $prefix = [];

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $ops = (string) ($store['ops_number'] ?? '');
            if ($ops === $q) {
                $exact[] = $store;
            } elseif ($ops !== '' && strpos($ops, $q) === 0) {
                $prefix[] = $store;
            }
        }

        return array_merge($exact, $prefix);
    }

    /**
     * @param  array  $stores
     * @return array
     */
    private function filterByText(array $stores, string $q): array
    {
        $tokens = preg_split('/\s+/u', $this->normalizeText($q), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return [];
        }

        $matched = [];
        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $haystack = $this->normalizeText(implode(' ', [
                $store['city'] ?? '',
                $store['street'] ?? '',
                $store['house'] ?? '',
                $store['ops_name'] ?? '',
                $store['name'] ?? '',
                $store['ops_number'] ?? '',
            ]));

            $allFound = true;
            foreach ($tokens as $token) {
                if (mb_strpos($haystack, $token) === false) {
                    $allFound = false;
                    break;
                }
            }

            if ($allFound) {
                $matched[] = $store;
            }
        }

        return $matched;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace(
            '/\b(ул\.?|улица|пр-т|пр\.?|проспект|пер\.?|переулок|пл\.?|площадь|б-р|бульвар|ш\.?|шоссе|тр-т|тракт)\b/u',
            ' ',
            $text
        );
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param  array  $store
     * @return array<string, mixed>
     */
    private function formatItem(array $store): array
    {
        $id        = (int) ($store['id'] ?? $store['store_id'] ?? 0);
        $opsNumber = (string) ($store['ops_number'] ?? '');
        $opsName   = (string) ($store['ops_name'] ?? '');
        $city      = (string) ($store['city'] ?? '');
        $street    = (string) ($store['street'] ?? '');
        $house     = (string) ($store['house'] ?? '');

        $address = trim($city);
        $streetLine = trim($street . ($house !== '' ? ', ' . $house : ''));
        if ($streetLine !== '') {
            $address = $address !== '' ? ($address . ', ' . $streetLine) : $streetLine;
        }

        $label = 'ОПС ' . ($opsNumber !== '' ? $opsNumber : $id);
        if ($address !== '') {
            $label .= ' · ' . $address;
        }

        return [
            'id'         => $id,
            'ops_number' => $opsNumber,
            'ops_name'   => $opsName,
            'city'       => $city,
            'street'     => $street,
            'house'      => $house,
            'label'      => $label,
        ];
    }
}
