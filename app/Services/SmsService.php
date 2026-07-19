<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends SMS via sms.by API.
 * Mirrors GAS sendSms(i, jsonObject, flag) in backend/General.gs.
 *
 * Flag values:
 *   0 — order shipped (Отправлено)
 *   1 — order arrived at branch (В отделении)
 *   2 — reminder (5-day or 10-day while in branch)
 *
 * Rules are stored in tenant_settings.sms_rules as a comma-separated string,
 * e.g. "Отправка,В отделении,Напоминание 5 день,Напоминание 10 день".
 * Mirrors GAS cell H1 in the active sheet.
 */
class SmsService
{
    private const SEND_URL  = 'https://app.sms.by/api/v1/sendQuickSMS';
    private const CHECK_URL = 'https://app.sms.by/api/v1/checkSMS';

    private string $token;
    private string $alphanameId;
    private string $rules;

    public function __construct(string $token, string $alphanameId, string $rules)
    {
        $this->token       = $token;
        $this->alphanameId = $alphanameId;
        $this->rules       = $rules;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Send an SMS for the given order and flag if rules permit.
     *
     * @param  Order $order
     * @param  int   $flag  0=shipped, 1=at branch, 2=reminder
     * @return bool  true if SMS was sent (or not needed)
     */
    public function sendForOrder(Order $order, int $flag): bool
    {
        if (!$this->rules) {
            Log::debug('SmsService: disabled (no rules)', ['order_id' => $order->id]);
            return false;
        }

        $message = $this->buildMessage($order, $flag);

        if (!$message) {
            return false;
        }

        $phone = '+375' . preg_replace('/\D/', '', (string) $order->phone);

        $url = self::SEND_URL . '?' . http_build_query([
            'token'        => $this->token,
            'message'      => $message['text'],
            'phone'        => $phone,
            'alphaname_id' => $this->alphanameId,
        ]);

        try {
            $response = Http::timeout(30)->post($url);

            if (!$response->successful()) {
                Log::warning('SmsService: send HTTP error', [
                    'order_id' => $order->id,
                    'status'   => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();

            if (empty($data['sms_id'])) {
                Log::warning('SmsService: no sms_id in response', [
                    'order_id' => $order->id,
                    'response' => $data,
                ]);
                return false;
            }

            // Append comment to sms_log (deduplication key for future runs)
            $currentLog = (string) ($order->sms_log ?? '');
            $newLog     = $currentLog
                ? $currentLog . ', ' . $message['comment']
                : $message['comment'];

            $order->updateQuietly(['sms_log' => $newLog]);

            Log::info('SmsService: sent', [
                'order_id' => $order->id,
                'flag'     => $flag,
                'comment'  => $message['comment'],
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('SmsService: exception', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build the SMS message text and comment string, or return null if not applicable.
     *
     * @return array|null  ['text' => string, 'comment' => string]
     */
    private function buildMessage(Order $order, int $flag): ?array
    {
        $smsLog  = (string) ($order->sms_log ?? '');
        $name    = $this->getFirstMiddleName($order->full_name ?? '');
        $track   = (string) ($order->track_number ?? '');
        $today   = Carbon::now()->format('d.m.Y');

        if ($flag === 0
            && str_contains($this->rules, 'Отправка')
            && !str_contains($smsLog, 'об отправке')
        ) {
            return [
                'text'    => "Здравствуйте! {$name}. Ваш заказ отправлен. Трек-номер для отслеживания - {$track}",
                'comment' => "{$today} - об отправке",
            ];
        }

        if ($flag === 1
            && str_contains($this->rules, 'В отделении')
            && !str_contains($smsLog, 'в отделении')
        ) {
            return [
                'text'    => "Здравствуйте! {$name}. Ваш заказ прибыл в отделение. Вы можете его забрать по номеру - {$track}",
                'comment' => "{$today} - в отделении",
            ];
        }

        if ($flag === 2) {
            $daysPassed = (int) Carbon::now()->diffInDays($order->status_changed_at);

            if (
                str_contains($this->rules, 'Напоминание 5 день')
                && !str_contains($smsLog, '5 день')
                && $daysPassed >= 5
                && $daysPassed < 6
            ) {
                return [
                    'text'    => "Здравствуйте! {$name}. Ваш заказ - {$track} ждет вас в отделении. Заберите его, пожалуйста!",
                    'comment' => "{$today} - 5 день",
                ];
            }

            if (
                str_contains($this->rules, 'Напоминание 10 день')
                && !str_contains($smsLog, '10 день')
                && $daysPassed >= 10
                && $daysPassed < 11
            ) {
                return [
                    'text'    => "Здравствуйте! {$name}. Срок хранения заказа - {$track} подходит к концу. Заберите, пожалуйста, его в ближайшее время!",
                    'comment' => "{$today} - 10 день",
                ];
            }
        }

        return null;
    }

    /**
     * Extract "Имя Отчество" from "Фамилия Имя Отчество".
     * Mirrors GAS: name = fioParts[1] + ' ' + fioParts[2]
     */
    private function getFirstMiddleName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return trim(($parts[1] ?? '') . ' ' . ($parts[2] ?? ''));
    }
}
