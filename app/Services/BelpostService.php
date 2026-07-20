<?php

namespace App\Services;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BelpostService
{
    private const API_BASE    = 'https://api.belpost.by';
    private const LIST_V1     = '/api/v1/batch-mailing/list';
    private const ITEM_V2     = '/api/v2/batch-mailing/list/{id}/item';
    private const COMMIT_V2   = '/api/v2/batch-mailing/list/{id}/commit';
    private const DOWNLOAD_V1 = '/api/v1/batch-mailing/documents/{id}/download';

    private string $authToken;
    private string $elc;
    private int    $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId  = $tenantId;
        $this->authToken = TenantSetting::get('auth_token_bp', '');
        $this->elc       = TenantSetting::get('elc', '');
    }

    // ─── createList ───────────────────────────────────────────────────────────

    /**
     * Create a new batch-mailing list on Belpost API v1.
     * Mirrors GAS createList().
     *
     * @param  string $postalDeliveryType  Belpost type code (e.g. "parcel", "ecommerce")
     * @param  string $whoPays             'Покупатель' | 'Продавец'
     * @return MailBatch  Created and persisted batch record
     * @throws \RuntimeException on API or parsing error
     */
    public function createList(string $postalDeliveryType, string $whoPays): MailBatch
    {
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->post(self::API_BASE . self::LIST_V1, [
                'direction'            => 'internal',
                'payment_type'         => 'electronic_personal_account',
                'postal_delivery_type' => $postalDeliveryType,
                'negotiated_rate'      => '1',
                'is_declared_value'    => false,
                'is_partial_receipt'   => false,
                'is_priority'          => 0,
                'card_number'          => $this->elc,
            ]);

        if (!$response->successful()) {
            $this->throwApiError('createList', $response);
        }

        $data    = $response->json();
        $batchId = (string)($data['id'] ?? '');

        if (!$batchId) {
            throw new \RuntimeException('Belpost createList: API returned no batch id. Body: ' . $response->body());
        }

        return MailBatch::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'batch_id'  => $batchId,
            'type'      => $postalDeliveryType,
            'who_pays'  => $whoPays,
            'status'    => MailBatch::STATUS_DRAFT,
        ]);
    }

    // ─── createItem ───────────────────────────────────────────────────────────

    /**
     * Create a single item in the batch on Belpost API v2.
     * Mirrors GAS createItem() payload construction.
     *
     * @param  MailBatch   $batch
     * @param  Order       $order
     * @param  string|null $belpostAddressId  Pre-resolved Belpost address id (from manual modal)
     * @return array  ['success' => bool, 'track_number' => string|null, 'error' => string|null]
     */
    public function createItem(MailBatch $batch, Order $order, ?string $belpostAddressId = null): array
    {
        $batch->refresh();

        // ── 1. Address resolution ──
        // Priority: explicit param → persisted on order → autoResolve
        $addressId = $belpostAddressId ?? ($order->belpost_address_id ?: null);
        $resolvedItem = null;

        if (!$addressId) {
            /** @var AddressService $addressService */
            $addressService = app(AddressService::class);
            $resolvedItem = $addressService->autoResolve(
                (string)($order->city ?? ''),
                (string)($order->street ?? ''),
                (string)($order->building ?? '')
            );

            if (!$resolvedItem) {
                return [
                    'success'      => false,
                    'track_number' => null,
                    'error'        => 'address_not_found',
                    'error_message' => 'Адрес не найден в справочнике Белпочты',
                ];
            }

            $addressId = (string)$resolvedItem['id'];
        }

        // ── 2. Weight + cash_on_delivery ──
        [$fullWeight, $cashOnDelivery] = $this->calculateWeightAndCod($order);

        // ── 3. FIO split ──
        [$lastName, $firstName, $secondName] = $this->splitFio((string)$order->full_name);

        // ── 4. Notification + email (ecommerce) ──
        $isEcommerce  = str_contains((string)$batch->type, 'ecommerce');
        $notification = $isEcommerce ? '5' : '0';
        $senderEmail  = $isEcommerce ? (TenantSetting::get('belpost_sender_email', '') ?: '') : '';
        $shelfLife    = $isEcommerce ? (TenantSetting::get('shelf_life', '10') ?: '10') : null;

        // ── 5. Recipient payment ──
        $whoPays          = $batch->who_pays ?? 'Покупатель';
        $recipientPayment = $whoPays !== 'Продавец';

        Log::info('BelpostService::createItem payment', [
            'batch_id'          => $batch->batch_id,
            'who_pays'          => $whoPays,
            'recipient_payment' => $recipientPayment,
            'order_id'          => $order->id,
        ]);

        // ── 6. Build payload (mirrors GAS createItem v2) ──
        $payload = [
            'recipient_contact_widget_data' => [
                'person' => [
                    'type'        => 'individual',
                    'first_name'  => $firstName,
                    'last_name'   => $lastName,
                    'second_name' => $secondName,
                    'email'       => null,
                    'phone'       => '375' . ltrim((string)$order->phone, '+375'),
                ],
                'address' => [
                    'id'           => $addressId,
                    'country_code' => 'BY',
                    'type'         => 'address',
                    'house'        => trim((string)($order->building ?? '')),
                    'cell_number'  => null,
                    'block'        => ($order->housing  ?? '') ?: null,
                    'flat'         => ($order->apartment ?? '') ?: null,
                ],
            ],
            's10code'               => '',
            'notification_s10code'  => '',
            'notification'          => $notification,
            'weight'                => $fullWeight,
            'count'                 => 1,
            'addons' => [
                'phone'                       => '',
                'email'                       => $senderEmail,
                'shelf_life'                  => $shelfLife,
                'declared_value'              => '',
                'cash_on_delivery'            => $cashOnDelivery,
                'careful_fragile'             => '',
                'hand_over_personally'        => null,
                'subpoena'                    => null,
                'service'                     => null,
                'government'                  => null,
                'military'                    => null,
                'description'                 => '',
                'bulky'                       => '',
                'recipient_payment'           => $recipientPayment,
                'partial_return'              => null,
                'documents_return'            => null,
                'deliver_to_work'             => null,
                'open_upon_delivery'          => null,
                'time_of_delivery'            => null,
                'free_return'                 => null,
                'notification'                => null,
                'coordinate_delivery_interval' => null,
            ],
        ];

        // ── 7. POST to Belpost ──
        $url = self::API_BASE . str_replace('{id}', $batch->batch_id, self::ITEM_V2);
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->post($url, $payload);

        if (!$response->successful()) {
            $body   = $response->body();
            $detail = $this->extractErrorDetail($body);
            Log::warning('BelpostService::createItem HTTP error', [
                'order_id' => $order->id,
                'status'   => $response->status(),
                'body'     => $body,
            ]);
            return [
                'success'       => false,
                'track_number'  => null,
                'error'         => 'api_error',
                'error_message' => "HTTP {$response->status()}" . ($detail ? ": {$detail}" : ''),
            ];
        }

        // ── 8. Parse v2 response ──
        $obj    = $response->json();
        $s10code = $obj['s10code'] ?? null;
        $widget  = $obj['recipient_contact_widget_data'] ?? null;
        $addr    = $widget['address'] ?? null;
        $respCity   = isset($addr['city'])   ? mb_strtolower(trim($addr['city']))   : '';
        $respStreet = isset($addr['street']) ? mb_strtolower(trim($addr['street'])) : '';

        $sheetCity   = mb_strtolower(trim((string)($order->city ?? '')));
        $sheetStreet = mb_strtolower(trim((string)($order->street ?? '')));

        if ($s10code && $addr && $respCity && $respStreet) {
            if (
                (str_contains($sheetCity, $respCity) || str_contains($respCity, $sheetCity))
                && (str_contains($sheetStreet, $respStreet) || str_contains($respStreet, $sheetStreet))
            ) {
                // ── 9. Update order ──
                $order->update([
                    'status'            => 'Оформлен',
                    'status_changed_at' => now(),
                    'track_number'      => $s10code,
                    'mail_batch_id'     => $batch->id,
                ]);

                Log::info('BelpostService::createItem success', [
                    'order_id'     => $order->id,
                    'track_number' => $s10code,
                    'item_id'      => $obj['id'] ?? null,
                ]);

                return ['success' => true, 'track_number' => $s10code, 'error' => null, 'error_message' => null];
            }

            // Address mismatch
            return [
                'success'       => false,
                'track_number'  => null,
                'error'         => 'address_mismatch',
                'error_message' => "Белпочта вернула другой адрес: {$respCity}, {$respStreet}",
            ];
        }

        return [
            'success'       => false,
            'track_number'  => null,
            'error'         => 'invalid_response',
            'error_message' => 'Белпочта вернула неполный ответ (нет s10code или адреса)',
        ];
    }

    // ─── commitActiveList ─────────────────────────────────────────────────────

    /**
     * Commit the batch on Belpost API v2.
     * Updates batch status to STATUS_COMMITTED and stores id_to_download.
     *
     * @param  MailBatch $batch
     * @return string  id_to_download
     * @throws \RuntimeException
     */
    public function commitActiveList(MailBatch $batch): string
    {
        $url = self::API_BASE . str_replace('{id}', $batch->batch_id, self::COMMIT_V2);

        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->post($url);

        if (!$response->successful()) {
            $this->throwApiError('commitActiveList', $response);
        }

        $data        = $response->json();
        $idToDownload = (string)($data['documents']['id'] ?? '');

        if (!$idToDownload) {
            throw new \RuntimeException('Belpost commitActiveList: no documents.id in response. Body: ' . $response->body());
        }

        $batch->update([
            'status'         => MailBatch::STATUS_COMMITTED,
            'id_to_download' => $idToDownload,
        ]);

        return $idToDownload;
    }

    // ─── downloadDocuments ────────────────────────────────────────────────────

    /**
     * Download the ZIP archive from Belpost, extract PDFs, store on disk.
     * Returns the storage-relative path to the merged/stored PDF.
     *
     * ZIP contains multiple per-page PDFs; we keep them all in a folder
     * named after the batch and serve as a download bundle.
     *
     * @param  string $idToDownload
     * @param  string $batchId  Used as directory name
     * @return string  Storage path to the stored zip (e.g. "belpost/pdf/123.zip")
     * @throws \RuntimeException
     */
    public function downloadDocuments(string $idToDownload, string $batchId): string
    {
        $url = self::API_BASE . str_replace('{id}', $idToDownload, self::DOWNLOAD_V1);

        $response = Http::timeout(120)
            ->withHeaders($this->headers())
            ->get($url);

        if (!$response->successful()) {
            $this->throwApiError('downloadDocuments', $response);
        }

        $zipContent = $response->body();
        if (!$zipContent) {
            throw new \RuntimeException('Belpost downloadDocuments: empty response body');
        }

        // Store ZIP on disk
        $zipPath = "belpost/pdf/{$batchId}.zip";
        Storage::put($zipPath, $zipContent);

        Log::info('BelpostService::downloadDocuments stored', ['path' => $zipPath, 'size' => strlen($zipContent)]);

        return $zipPath;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-cache',
            'Content-Type'  => 'application/json',
            'Authorization' => $this->authToken,
        ];
    }

    /**
     * Calculate full_weight (g) and cash_on_delivery (kopecks or units per API).
     *
     * @return array [int $weight, int $cashOnDelivery]
     */
    private function calculateWeightAndCod(Order $order): array
    {
        $goods      = $order->goods      ?? [];
        $quantities = $order->quantities ?? [];
        $prices     = $order->prices     ?? [];

        if (count($goods) !== count($quantities) || count($goods) !== count($prices)) {
            return [0, 0];
        }

        $fullWeight    = 0;
        $cashOnDelivery = 0;

        // Load all products for this tenant in one query
        $productWeights = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('name', $goods)
            ->pluck('weight', 'name')
            ->toArray();

        foreach ($goods as $i => $goodName) {
            $qty   = (int)($quantities[$i] ?? 1);
            $price = (float)($prices[$i] ?? 0);
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
        $parts      = explode(' ', trim($fio));
        $lastName   = $parts[0] ?? '';
        $firstName  = $parts[1] ?? '';
        $secondName = $parts[2] ?? '';

        return [$lastName, $firstName, $secondName];
    }

    private function extractErrorDetail(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $detail = $data['message'] ?? $data['error'] ?? $data['detail'] ?? '';
            return is_array($detail) ? json_encode($detail) : (string)$detail;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param  \Illuminate\Http\Client\Response $response
     * @throws \RuntimeException
     */
    private function throwApiError(string $method, $response): void
    {
        $detail = $this->extractErrorDetail($response->body());
        throw new \RuntimeException(
            "BelpostService::{$method} HTTP {$response->status()}" . ($detail ? ": {$detail}" : '')
        );
    }
}
