<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AddressService
{
    private const API_URL = 'https://api.belpost.by/api/v1/dictionary-list';

    /**
     * Validation rules required by Belpost dictionary-list endpoint.
     * Mirrors DICTIONARY_LIST_ADDITIONAL_RULES from ScSA.gs.
     */
    private const ADDITIONAL_RULES = [
        ['validation_rule_id' => '4',   'custom_value' => ['individual']],
        ['validation_rule_id' => '10',  'custom_value' => 30],
        ['validation_rule_id' => '16',  'custom_value' => 50],
        ['validation_rule_id' => '22',  'custom_value' => 30],
        ['validation_rule_id' => '28',  'custom_value' => 255],
        ['validation_rule_id' => '34',  'custom_value' => null],
        ['validation_rule_id' => '40',  'custom_value' => null],
        ['validation_rule_id' => '82',  'custom_value' => null],
        ['validation_rule_id' => '94',  'custom_value' => null],
        ['validation_rule_id' => '97',  'custom_value' => null],
        ['validation_rule_id' => '109', 'custom_value' => 8],
        ['validation_rule_id' => '193', 'custom_value' => null],
        ['validation_rule_id' => '100', 'custom_value' => ['address', 'subscriber_box', 'on_demand']],
        ['validation_rule_id' => '103', 'custom_value' => null],
        ['validation_rule_id' => '112', 'custom_value' => 6],
        ['validation_rule_id' => '115', 'custom_value' => 20],
        ['validation_rule_id' => '118', 'custom_value' => 255],
        ['validation_rule_id' => '133', 'custom_value' => 50],
        ['validation_rule_id' => '136', 'custom_value' => 50],
        ['validation_rule_id' => '88',  'custom_value' => ['BY']],
    ];

    /**
     * POST /api/v1/dictionary-list without Authorization.
     * Returns raw data[] array or empty array on failure.
     *
     * @param  string $query  Combined city + street (e.g. "Минск Ленина")
     * @return array
     */
    public function search(string $query): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Cache-Control' => 'no-cache'])
                ->post(self::API_URL, [
                    'name'             => 'address',
                    'search'           => $this->normalizeAddressPart($query),
                    'per_page'         => '30',
                    'page'             => '1',
                    'type'             => 'address',
                    'additional_rules' => self::ADDITIONAL_RULES,
                ]);

            if (!$response->successful()) {
                Log::warning('AddressService: dictionary-list returned HTTP ' . $response->status(), [
                    'query' => $query,
                    'body'  => $response->body(),
                ]);
                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::error('AddressService: search failed', ['error' => $e->getMessage(), 'query' => $query]);
            return [];
        }
    }

    /**
     * Format a single dictionary-list item for the frontend dropdown.
     * Matches the format used in ScSA.gs::formatAddressesForDisplay.
     *
     * @param  array $item  Single element from data[]
     * @return array
     */
    public function formatItem(array $item): array
    {
        $houses = $this->extractHousesFromParams($item['params'] ?? null);

        return [
            'id'           => $item['id'] ?? null,
            'city'         => $item['city'] ?? '',
            'city_type'    => $item['city_type'] ?? '',
            'street'       => $item['street'] ?? '',
            'street_type'  => $item['street_type'] ?? '',
            'district'     => $item['district'] ?? '',
            'postcode'     => $item['postcode'] ?? '',
            'houses'       => $houses,
            'params_null'  => ($item['params'] ?? null) === null,
            'label'        => $this->buildLabel($item, $houses),
        ];
    }

    /**
     * Format all search results for frontend consumption.
     *
     * @param  array $items  Raw data[] from search()
     * @return array
     */
    public function formatAll(array $items): array
    {
        return array_values(array_map([$this, 'formatItem'], $items));
    }

    /**
     * Find best matching address item for city + street from the order.
     * Returns null if no match or house not found.
     *
     * @param  string $city
     * @param  string $street
     * @param  string $building
     * @return array|null  Matched item with ops_id (= item id)
     */
    public function autoResolve(string $city, string $street, string $building): ?array
    {
        $items = $this->search($this->normalizeAddressPart($city) . ' ' . $this->normalizeAddressPart($street));

        foreach ($items as $item) {
            if (
                $this->addressPartsMatch($city, $item['city'] ?? '')
                && $this->addressPartsMatch($street, $item['street'] ?? '')
            ) {
                if ($this->isHouseAllowed($item, $building)) {
                    return $item;
                }
            }
        }

        return null;
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function normalizeAddressPart(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    public function addressPartsMatch(string $sheetValue, string $apiValue): bool
    {
        $sheet = $this->normalizeAddressPart($sheetValue);
        $api   = $this->normalizeAddressPart($apiValue);

        return $sheet !== '' && $api !== '' && (
            mb_strpos($sheet, $api) !== false || mb_strpos($api, $sheet) !== false
        );
    }

    /**
     * @param  array|null $params  item.params from dictionary-list
     * @return string[]
     */
    public function extractHousesFromParams(?array $params): array
    {
        if (!is_array($params)) {
            return [];
        }

        return array_values(array_map(
            fn($p) => $this->normalizeAddressPart((string)($p['house'] ?? '')),
            $params
        ));
    }

    public function isHouseAllowed(array $item, string $building): bool
    {
        // params === null means all houses on the street are allowed
        if (($item['params'] ?? null) === null) {
            return true;
        }

        $houses = $this->extractHousesFromParams($item['params']);
        return in_array($this->normalizeAddressPart($building), $houses, true);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function buildLabel(array $item, array $houses): string
    {
        $district   = $item['district']   ?? '';
        $postcode   = $item['postcode']   ?? '';
        $cityType   = $item['city_type']  ?? '';
        $city       = $item['city']       ?? '';
        $streetType = $item['street_type'] ?? '';
        $street     = $item['street']     ?? '';
        $housesStr  = ($item['params'] ?? null) === null
            ? 'все дома'
            : implode(', ', $houses);

        return "{$district}, {$postcode}, {$cityType} {$city}, {$streetType} {$street}, дома - ({$housesStr})";
    }
}
