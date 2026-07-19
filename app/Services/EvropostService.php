<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Europochta service.
 *
 * Phase 3 — tracking (getTracking).
 * Phase 4 — shipment creation:
 *   createItem()    — legacy JWT API (api.eurotorg.by:10352/Json)
 *   createItemNew() — v1.8.2 API (api-kassa.evropochta.by)
 */
class EvropostService
{
    // ─── Tracking ─────────────────────────────────────────────────────────────

    private const TRACKING_URL = 'https://evropochta.by/mvc/application/ops/tracking/';
    private const MAX_ATTEMPTS = 5;

    // ─── Legacy JWT API ───────────────────────────────────────────────────────

    private const JWT_URL = 'https://api.eurotorg.by:10352/Json';

    /**
     * Weight categories for the legacy JWT API (postal_weight_id → gram range).
     * IDs 20–27 are official Europochta values; weight limits are in grams.
     * createItemNew() sends raw weight, so this table is only used by createItem().
     */
    private const WEIGHT_CATEGORIES = [
        ['postal_weight_id' => '20', 'min' =>     0, 'max' =>  1000],
        ['postal_weight_id' => '21', 'min' =>  1001, 'max' =>  2000],
        ['postal_weight_id' => '22', 'min' =>  2001, 'max' =>  5000],
        ['postal_weight_id' => '23', 'min' =>  5001, 'max' => 10000],
        ['postal_weight_id' => '24', 'min' => 10001, 'max' => 15000],
        ['postal_weight_id' => '25', 'min' => 15001, 'max' => 20000],
        ['postal_weight_id' => '26', 'min' => 20001, 'max' => 25000],
        ['postal_weight_id' => '27', 'min' => 25001, 'max' => 30000],
    ];

    // ─── New API v1.8.2 ───────────────────────────────────────────────────────

    private const NEW_API_URL    = 'https://api-kassa.evropochta.by';
    private const STORES_PATH    = '/api/external/stores';
    private const CREATE_PATH    = '/api/external/postal/create';
    private const GOODS_ID       = '836884'; // constant per Europochta contract

    // ─── Tracking ─────────────────────────────────────────────────────────────

    /**
     * Fetch tracking events for an Europochta parcel.
     * Mirrors GAS Европочта block inside getStatus().
     *
     * @return array|null  Array of events [['timeX' => ..., 'client_info' => ...], ...] newest first,
     *                     or null on failure.
     */
    public function getTracking(string $trackNumber): ?array
    {
        $url = self::TRACKING_URL . '?number=' . urlencode($trackNumber);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Cache-Control' => 'no-cache',
                        'Content-Type'  => 'application/json',
                    ])
                    ->post($url);

                if ($response->successful()) {
                    $data = $response->json();

                    if (!is_array($data)) {
                        Log::warning('EvropostService::getTracking: non-array response', [
                            'track' => $trackNumber,
                            'body'  => $response->body(),
                        ]);
                        return null;
                    }

                    return $data;
                }

                Log::warning("EvropostService::getTracking attempt {$attempt} HTTP {$response->status()}", [
                    'track' => $trackNumber,
                ]);

            } catch (\Throwable $e) {
                Log::warning("EvropostService::getTracking attempt {$attempt} exception", [
                    'track' => $trackNumber,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::MAX_ATTEMPTS - 1) {
                sleep(2);
            }
        }

        return null;
    }

    // ─── New API v1.8.2 ───────────────────────────────────────────────────────

    /**
     * Create Europochta shipment via new API v1.8.2.
     * Mirrors GAS createItemEuroNew().
     *
     * Reads tenant settings: token_ep, contractor_unn, warehouse_id_start.
     *
     * @param  string $whoPays  'Покупатель' | 'Продавец'
     * @return array ['success' => bool, 'track_number' => string|null, 'error' => string|null, 'error_message' => string|null]
     */
    public function createItemNew(Order $order, int $tenantId, string $whoPays = 'Покупатель'): array
    {
        $token         = TenantSetting::get('token_ep', '');
        $contractorUnn = TenantSetting::get('contractor_unn', '');
        $warehouseStart = TenantSetting::get('warehouse_id_start', '');

        if (!$token) {
            return $this->failure('config_error', 'Не задан token_ep в настройках');
        }
        if (!$contractorUnn) {
            return $this->failure('config_error', 'Не задан contractor_unn в настройках');
        }
        if (!$warehouseStart) {
            return $this->failure('config_error', 'Не задан warehouse_id_start в настройках');
        }

        // ── 1. Calculate weight and COD ──
        [$fullWeight, $cashOnDelivery] = $this->calculateWeightAndCod($order, $tenantId);

        // ── 2. Get list of delivery offices ──
        $storesResp = $this->newApiRequest('GET', self::STORES_PATH . '?type=1', null, $token);

        if ($storesResp['status'] === 401) {
            return $this->failure('auth_error', 'Ошибка авторизации API Европочты (401). Проверьте token_ep.');
        }
        if ($storesResp['status'] !== 200) {
            $msg = $storesResp['body']['message'] ?? ('HTTP ' . $storesResp['status']);
            return $this->failure('api_error', 'Ошибка получения списка ОПС: ' . $msg);
        }

        $storesList = $storesResp['body'];
        if (!is_array($storesList)) {
            $storesList = $storesList['data'] ?? $storesList['stores'] ?? $storesList['Table'] ?? [];
        }

        // ── 3. Find destination office ──
        $storeIdFinish = $this->findStoreNew(
            $storesList,
            (string)($order->city ?? ''),
            (string)($order->street ?? ''),
            (string)($order->building ?? ''),
            (string)($order->ops_id ?? '')
        );

        if (!$storeIdFinish) {
            return $this->failure(
                'office_not_found',
                'Не найдено отделение выдачи для: ' . trim(implode(', ', array_filter([
                    $order->city, $order->street, $order->building,
                ])))
            );
        }

        // ── 4. FIO + phone ──
        [$lastName, $firstName, $secondName] = $this->splitFio((string)$order->full_name);
        $phone = '375' . ltrim((string)$order->phone, '+375');

        // ── 5. Who pays ──
        $shipmentPayer = $whoPays === 'Продавец' ? 0 : 1;

        // ── 6. Build payload ──
        $payload = [
            'delivery_type'              => 1,
            'weight'                     => $fullWeight,
            'is_auto_delivery'           => false,
            'payment_amount'             => (string)$cashOnDelivery,
            'declared_amount'            => (string)$cashOnDelivery,
            'store_id_start'             => (int)$warehouseStart,
            'store_id_finish'            => $storeIdFinish,
            'receiver_phone_number'      => $phone,
            'receiver_surname'           => $lastName,
            'receiver_name'              => $firstName,
            'receiver_patronymic_name'   => $secondName ?: '',
            'shipment_payer'             => $shipmentPayer,
            'cash_on_delivery_payer'     => 1,
            'contractor_unn'             => (int)$contractorUnn,
            'external_id'               => self::GOODS_ID,
        ];

        // ── 7. Create shipment ──
        $createResp = $this->newApiRequest('POST', self::CREATE_PATH, $payload, $token);

        if ($createResp['status'] === 401) {
            return $this->failure('auth_error', 'Ошибка авторизации API Европочты (401). Проверьте token_ep.');
        }

        if ($createResp['status'] === 200 && !empty($createResp['body']['number'])) {
            $trackNumber = $createResp['body']['number'];

            $order->update([
                'status'            => 'Оформлен',
                'status_changed_at' => now(),
                'track_number'      => $trackNumber,
            ]);

            Log::info('EvropostService::createItemNew success', [
                'order_id'     => $order->id,
                'track_number' => $trackNumber,
            ]);

            return ['success' => true, 'track_number' => $trackNumber, 'error' => null, 'error_message' => null];
        }

        $errMsg = $createResp['body']['message'] ?? ('HTTP ' . $createResp['status']);
        Log::warning('EvropostService::createItemNew failed', [
            'order_id' => $order->id,
            'status'   => $createResp['status'],
            'body'     => $createResp['body'],
        ]);

        return $this->failure('api_error', 'Ошибка создания бланка: ' . $errMsg);
    }

    // ─── Legacy JWT API ───────────────────────────────────────────────────────

    /**
     * Create Europochta shipment via legacy JWT API.
     * Mirrors GAS createItemEuro().
     *
     * Reads tenant settings: login_name_ep, password_ep, service_number_ep, warehouse_id_start.
     * Weight categories are hardcoded in WEIGHT_CATEGORIES (IDs 20–27).
     *
     * @param  string $whoPays  'Покупатель' | 'Продавец'
     * @return array ['success' => bool, 'track_number' => string|null, 'error' => string|null, 'error_message' => string|null]
     */
    public function createItem(Order $order, int $tenantId, string $whoPays = 'Покупатель'): array
    {
        $loginName     = TenantSetting::get('login_name_ep', '');
        $password      = TenantSetting::get('password_ep', '');
        $serviceNumber = TenantSetting::get('service_number_ep', '');
        $warehouseStart = TenantSetting::get('warehouse_id_start', '');

        if (!$loginName || !$password) {
            return $this->failure('config_error', 'Не заданы login_name_ep / password_ep в настройках');
        }

        // ── 1. Get JWT token ──
        $jwtResp = $this->jwtRequest(null, 'GetJWT', [
            'LoginName'       => $loginName,
            'Password'        => $password,
            'LoginNameTypeId' => 1,
        ], $serviceNumber);

        $jwt = $jwtResp['Table'][0]['JWT'] ?? null;

        if (!$jwt) {
            Log::warning('EvropostService::createItem JWT not received', ['order_id' => $order->id]);
            return $this->failure('auth_error', 'JWT токен не получен. Проверьте login_name_ep / password_ep.');
        }

        // ── 2. Calculate weight and COD ──
        [$fullWeight, $cashOnDelivery] = $this->calculateWeightAndCod($order, $tenantId);

        // ── 3. Resolve weight category ──
        $weightCat = $this->resolveWeightCategory($fullWeight);
        if (!$weightCat) {
            return $this->failure(
                'weight_category_not_found',
                "Не найдена категория веса для {$fullWeight} г."
            );
        }

        // ── 4. Get delivery offices ──
        $officesResp = $this->jwtRequest($jwt, 'Postal.OfficesOut', ['TypeSender' => '1'], $serviceNumber);
        $offices     = $officesResp['Table'] ?? [];

        // ── 5. Find destination office ──
        $warehouseIdFinish = $this->findOfficeJwt(
            $offices,
            (string)($order->city ?? ''),
            (string)($order->street ?? ''),
            (string)($order->building ?? ''),
            (string)($order->ops_id ?? '')
        );

        if (!$warehouseIdFinish) {
            return $this->failure(
                'office_not_found',
                'Не найдено отделение выдачи для: ' . trim(implode(', ', array_filter([
                    $order->city, $order->street, $order->building,
                ])))
            );
        }

        // ── 6. FIO + phone ──
        [$lastName, $firstName, $secondName] = $this->splitFio((string)$order->full_name);
        $phone = '375' . ltrim((string)$order->phone, '+375');

        // ── 7. Who pays ──
        $recipientPayment = $whoPays === 'Продавец' ? '0' : '1';

        // ── 8. PutOrder ──
        $putResp = $this->jwtRequest($jwt, 'Postal.PutOrder', [
            'GoodsId'                 => self::GOODS_ID,
            'PostDeliveryTypeId'      => '1',
            'PostalWeightId'          => $weightCat,
            'CashOnDeliverySum'       => $cashOnDelivery,
            'WarehouseIdStart'        => $warehouseStart,
            'WarehouseIdFinish'       => $warehouseIdFinish,
            'PhoneNumberReciever'     => $phone,
            'Name1Reciever'           => $lastName,
            'Name2Reciever'           => $firstName,
            'Name3Reciever'           => $secondName,
            'IsRecieverShipping'      => $recipientPayment,
            'IsRecieverCashOnDelivery' => '1',
        ], $serviceNumber);

        $trackNumber = $putResp['Table'][0]['Number'] ?? null;

        if ($trackNumber) {
            $order->update([
                'status'            => 'Оформлен',
                'status_changed_at' => now(),
                'track_number'      => $trackNumber,
            ]);

            Log::info('EvropostService::createItem success', [
                'order_id'     => $order->id,
                'track_number' => $trackNumber,
            ]);

            return ['success' => true, 'track_number' => $trackNumber, 'error' => null, 'error_message' => null];
        }

        Log::warning('EvropostService::createItem PutOrder returned no track', [
            'order_id' => $order->id,
            'response' => $putResp,
        ]);

        return $this->failure('api_error', 'Европочта не вернула номер бланка');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Send request to legacy JWT API endpoint.
     *
     * @return array  Decoded JSON response
     */
    private function jwtRequest(?string $jwt, string $methodName, array $data, string $serviceNumber): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Cache-Control' => 'no-cache',
                    'Content-Type'  => 'application/json',
                ])
                ->post(self::JWT_URL, [
                    'CRC'    => '',
                    'Packet' => [
                        'JWT'           => $jwt,
                        'MethodName'    => $methodName,
                        'ServiceNumber' => $serviceNumber,
                        'Data'          => $data,
                    ],
                ]);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error("EvropostService::jwtRequest {$methodName} exception", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Send request to new API v1.8.2.
     *
     * @return array ['status' => int, 'body' => array]
     */
    private function newApiRequest(string $method, string $path, ?array $payload, string $token): array
    {
        try {
            $req = Http::timeout(30)
                ->withHeaders([
                    'Cache-Control' => 'no-cache',
                    'Content-Type'  => 'application/json',
                    'Token'         => $token,
                ]);

            $url = self::NEW_API_URL . $path;

            $response = strtoupper($method) === 'GET'
                ? $req->get($url)
                : $req->post($url, $payload ?? []);

            $body = $response->json();
            if (!is_array($body)) {
                $body = ['message' => $response->body()];
            }

            return ['status' => $response->status(), 'body' => $body];
        } catch (\Throwable $e) {
            Log::error("EvropostService::newApiRequest {$method} {$path} exception", ['error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()]];
        }
    }

    /**
     * Calculate total weight (grams) and cash-on-delivery amount.
     *
     * @return array [int $weight, int $cashOnDelivery]
     */
    private function calculateWeightAndCod(Order $order, int $tenantId): array
    {
        $goods      = $order->goods      ?? [];
        $quantities = $order->quantities ?? [];
        $prices     = $order->prices     ?? [];

        if (!$goods || count($goods) !== count($quantities) || count($goods) !== count($prices)) {
            return [0, 0];
        }

        $productWeights = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('name', $goods)
            ->pluck('weight', 'name')
            ->toArray();

        $fullWeight    = 0;
        $cashOnDelivery = 0;

        foreach ($goods as $i => $goodName) {
            $qty    = (int)($quantities[$i] ?? 1);
            $price  = (float)($prices[$i] ?? 0);
            $weight = (float)($productWeights[trim($goodName)] ?? 0);

            $cashOnDelivery += (int)round($qty * $price);
            $fullWeight     += (int)round($qty * $weight);
        }

        return [$fullWeight, $cashOnDelivery];
    }

    /**
     * Split "Фамилия Имя Отчество" into parts.
     *
     * @return array [string $lastName, string $firstName, string $secondName]
     */
    private function splitFio(string $fio): array
    {
        $parts = explode(' ', trim($fio));
        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
            $parts[2] ?? '',
        ];
    }

    /**
     * Resolve PostalWeightId from WEIGHT_CATEGORIES const (legacy JWT API only).
     * Weight is in grams. Returns the postal_weight_id string or null if out of range.
     */
    private function resolveWeightCategory(int $weightGrams): ?string
    {
        foreach (self::WEIGHT_CATEGORIES as $cat) {
            if ($weightGrams >= $cat['min'] && $weightGrams <= $cat['max']) {
                return $cat['postal_weight_id'];
            }
        }

        return null;
    }

    /**
     * Find warehouse ID in Postal.OfficesOut response (legacy JWT API).
     * Matches by OPS number (ops_id) or by street/building.
     */
    private function findOfficeJwt(array $offices, string $city, string $street, string $building, string $opsNumber): ?string
    {
        $cityLower = mb_strtolower(trim($city));

        $matches = array_filter($offices, function ($office) use ($cityLower) {
            $addr5 = mb_strtolower($office['Address5Name'] ?? '');
            return str_contains($addr5, $cityLower);
        });

        foreach ($matches as $office) {
            if ($opsNumber !== '') {
                $name = preg_replace('/\s+/', '', $office['WarehouseName'] ?? '');
                if (preg_match('/Отделение№(\d{1,3})/', $name, $m) && $m[1] === $opsNumber) {
                    return (string)$office['WarehouseId'];
                }
            } else {
                $addr4 = $office['Address4Name'] ?? '';
                $addr3 = $office['Address3Name'] ?? '';
                if (str_contains($addr4, $street) && str_contains($addr3, $building)) {
                    return (string)$office['WarehouseId'];
                }
            }
        }

        return null;
    }

    /**
     * Find store ID in /api/external/stores response (new API v1.8.2).
     * Matches by OPS number or by street/building.
     */
    private function findStoreNew(array $stores, string $city, string $street, string $building, string $opsNumber): ?int
    {
        $cityLower = mb_strtolower(trim($city));

        $matches = array_filter($stores, function ($store) use ($cityLower) {
            $storeCity = mb_strtolower($store['city'] ?? '');
            return str_contains($storeCity, $cityLower) || str_contains($cityLower, $storeCity);
        });

        foreach ($matches as $store) {
            if ($opsNumber !== '') {
                if ((string)($store['ops_number'] ?? '') === $opsNumber) {
                    return (int)($store['id'] ?? $store['store_id'] ?? 0) ?: null;
                }
            } else {
                $storeStreet   = $store['street']   ?? '';
                $storeBuilding = $store['house']     ?? '';
                if (
                    str_contains($street, $storeStreet) &&
                    str_contains($storeBuilding, $building)
                ) {
                    $id = (int)($store['id'] ?? $store['store_id'] ?? 0);
                    return $id ?: null;
                }
            }
        }

        return null;
    }

    /**
     * Build a standardised failure response.
     */
    private function failure(string $error, string $message): array
    {
        return [
            'success'       => false,
            'track_number'  => null,
            'error'         => $error,
            'error_message' => $message,
        ];
    }
}
