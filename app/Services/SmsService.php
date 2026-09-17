<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Support\PhoneNormalizer;
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
 *   2 — reminder (day 1 or day 2 while in branch)
 *
 * Rules are stored in tenant_settings.sms_rules as a comma-separated string,
 * e.g. "Отправка,В отделении,Напоминание 5 день,Напоминание 10 день".
 * Mirrors GAS cell H1 in the active sheet.
 */
class SmsService
{
    public const DEFAULT_TPL_SHIPPED = 'Здравствуйте! {name}. Ваш заказ отправлен. Трек-номер для отслеживания - {track}';
    public const DEFAULT_TPL_ARRIVED = 'Здравствуйте! {name}. Ваш заказ прибыл в отделение. Вы можете его забрать по номеру - {track}';
    public const DEFAULT_TPL_REMINDER = 'Здравствуйте! {name}. Ваш заказ - {track} ждет вас в отделении. Заберите его, пожалуйста!';

    private const SEND_URL  = 'https://app.sms.by/api/v1/sendQuickSMS';
    private const CHECK_URL = 'https://app.sms.by/api/v1/checkSMS';

    public function __construct(
        private string $token,
        private string $alphanameId,
        private string $rules,
        private string $tplShipped = self::DEFAULT_TPL_SHIPPED,
        private string $tplArrived = self::DEFAULT_TPL_ARRIVED,
        private string $tplReminder = self::DEFAULT_TPL_REMINDER,
        private int $reminderDay1 = 5,
        private int $reminderDay2 = 10,
    ) {
        if ($this->tplShipped === '') {
            $this->tplShipped = self::DEFAULT_TPL_SHIPPED;
        }
        if ($this->tplArrived === '') {
            $this->tplArrived = self::DEFAULT_TPL_ARRIVED;
        }
        if ($this->tplReminder === '') {
            $this->tplReminder = self::DEFAULT_TPL_REMINDER;
        }
        if ($this->reminderDay1 < 1) {
            $this->reminderDay1 = 5;
        }
        if ($this->reminderDay2 < 1) {
            $this->reminderDay2 = 10;
        }
    }

    /**
     * Load SMS credentials and templates for a tenant.
     * Returns null when token or alphaname is missing. Empty rules is allowed.
     */
    public static function forTenant(int $tenantId): ?self
    {
        app()->instance('current_tenant_id', $tenantId);

        $token       = trim((string) TenantSetting::get('token_sms_by', ''));
        $alphanameId = trim((string) TenantSetting::get('alphaname_id', ''));

        if ($token === '' || $alphanameId === '') {
            return null;
        }

        $rules      = (string) TenantSetting::get('sms_rules', '');
        $tplShipped = trim((string) TenantSetting::get('sms_tpl_shipped', ''));
        $tplArrived = trim((string) TenantSetting::get('sms_tpl_arrived', ''));
        $tplReminder = trim((string) TenantSetting::get('sms_tpl_reminder', ''));
        $day1       = (int) (TenantSetting::get('sms_reminder_day_1', '5') ?: 5);
        $day2       = (int) (TenantSetting::get('sms_reminder_day_2', '10') ?: 10);

        return new self(
            $token,
            $alphanameId,
            $rules,
            $tplShipped !== '' ? $tplShipped : self::DEFAULT_TPL_SHIPPED,
            $tplArrived !== '' ? $tplArrived : self::DEFAULT_TPL_ARRIVED,
            $tplReminder !== '' ? $tplReminder : self::DEFAULT_TPL_REMINDER,
            $day1,
            $day2,
        );
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

        $phone = PhoneNormalizer::toInternationalPlus($order->phone);

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
        $smsLog = (string) ($order->sms_log ?? '');
        $today  = Carbon::now()->format('d.m.Y');

        if ($flag === 0
            && str_contains($this->rules, 'Отправка')
            && !str_contains($smsLog, 'об отправке')
        ) {
            return [
                'text'    => $this->applyPlaceholders($this->tplShipped, $order),
                'comment' => "{$today} - об отправке",
            ];
        }

        if ($flag === 1
            && str_contains($this->rules, 'В отделении')
            && !str_contains($smsLog, 'в отделении')
        ) {
            return [
                'text'    => $this->applyPlaceholders($this->tplArrived, $order),
                'comment' => "{$today} - в отделении",
            ];
        }

        if ($flag === 2) {
            $daysPassed = (int) Carbon::now()->diffInDays($order->status_changed_at);

            foreach ([$this->reminderDay1, $this->reminderDay2] as $day) {
                if (
                    str_contains($this->rules, "Напоминание {$day} день")
                    && !str_contains($smsLog, "{$day} день")
                    && $daysPassed >= $day
                    && $daysPassed < $day + 1
                ) {
                    return [
                        'text'    => $this->applyPlaceholders($this->tplReminder, $order, $day),
                        'comment' => "{$today} - {$day} день",
                    ];
                }
            }
        }

        return null;
    }

    private function applyPlaceholders(string $template, Order $order, ?int $days = null): string
    {
        $goods = $order->goods;
        $tovar = '';
        if (is_array($goods) && isset($goods[0]) && $goods[0] !== null) {
            $tovar = (string) $goods[0];
        }

        return strtr($template, [
            '{name}'  => $this->getFirstMiddleName($order->full_name ?? ''),
            '{track}' => (string) ($order->track_number ?? ''),
            '{tovar}' => $tovar,
            '{days}'  => $days !== null ? (string) $days : '',
        ]);
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
