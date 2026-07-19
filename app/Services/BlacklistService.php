<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks a phone number against the blacks.by blacklist.
 * Mirrors GAS checkBlackList() in backend/Blacks.by.gs.
 *
 * API: GET https://blacks.by/api.php?getphone={phone}&id={id}&api_key={key}
 * Phone format: 80XXXXXXXXX (without + or country code)
 */
class BlacklistService
{
    private const API_URL = 'https://blacks.by/api.php';

    private string $apiKey;
    private string $listId;

    public function __construct(string $apiKey, string $listId)
    {
        $this->apiKey = $apiKey;
        $this->listId = $listId;
    }

    /**
     * Check if the phone number is in the blacklist.
     *
     * @param  string $phone  Raw phone digits (e.g. "291234567" — without 80 or +375)
     * @return bool  true if the number IS blacklisted
     */
    public function check(string $phone): bool
    {
        // Format: 80XXXXXXXXX (as in GAS: '80' + phone)
        $formatted = '80' . preg_replace('/\D/', '', $phone);

        $url = self::API_URL . '?' . http_build_query([
            'getphone' => $formatted,
            'id'       => $this->listId,
            'api_key'  => $this->apiKey,
        ]);

        try {
            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                Log::warning('BlacklistService: HTTP error', [
                    'phone'  => $formatted,
                    'status' => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK') {
                Log::warning('BlacklistService: API error', [
                    'phone'    => $formatted,
                    'response' => $data,
                ]);
                return false;
            }

            return $data['response'] === true;

        } catch (\Throwable $e) {
            Log::error('BlacklistService: exception', [
                'phone' => $formatted,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
