<?php

namespace App\Services;

use App\Exceptions\TelegramException;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Http;

class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private string $token,
        private string $chatId
    ) {
    }

    /**
     * Load Telegram credentials for a tenant.
     * Returns null when token or chat_id is missing.
     */
    public static function forTenant(int $tenantId): ?self
    {
        app()->instance('current_tenant_id', $tenantId);

        $token  = trim((string) TenantSetting::get('telegram_bot_token', ''));
        $chatId = trim((string) TenantSetting::get('telegram_chat_id', ''));

        if ($token === '' || $chatId === '') {
            return null;
        }

        return new self($token, $chatId);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $text): array
    {
        try {
            $response = Http::timeout(30)
                ->asJson()
                ->post($this->methodUrl('sendMessage'), [
                    'chat_id' => $this->chatId,
                    'text'    => $text,
                ]);
        } catch (\Throwable $e) {
            throw new TelegramException('Не удалось связаться с Telegram');
        }

        return $this->assertOk($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $caption, string $pdfBinary, string $filename): array
    {
        try {
            $response = Http::timeout(30)
                ->attach('document', $pdfBinary, $filename)
                ->post($this->methodUrl('sendDocument'), [
                    'chat_id' => $this->chatId,
                    'caption' => $caption,
                ]);
        } catch (\Throwable $e) {
            throw new TelegramException('Не удалось связаться с Telegram');
        }

        return $this->assertOk($response);
    }

    private function methodUrl(string $method): string
    {
        return self::API_BASE . $this->token . '/' . $method;
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     * @return array<string, mixed>
     */
    private function assertOk($response): array
    {
        $data = $response->json();
        if (!is_array($data)) {
            throw new TelegramException('Ошибка Telegram');
        }

        if (($data['ok'] ?? false) === true) {
            return $data;
        }

        $description = trim((string) ($data['description'] ?? ''));

        throw new TelegramException($description !== '' ? $description : 'Ошибка Telegram');
    }
}
