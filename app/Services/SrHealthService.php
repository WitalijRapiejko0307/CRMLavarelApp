<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Support\SrResult;
use Illuminate\Support\Facades\Cache;

/**
 * Persistent state of the SalesRender integration per tenant.
 *
 * Every SalesRender request reports its result here, so a missing permission or a broken token
 * becomes visible (banner, settings, order badges) instead of a silent log line.
 */
class SrHealthService
{
    public const STATE_OK                = 'ok';
    public const STATE_PERMISSION_DENIED = 'permission_denied';
    public const STATE_AUTH_FAILED       = 'auth_failed';
    public const STATE_CONFIG_ERROR      = 'config_error';

    /** GraphQL operation → restriction key reported by SalesRender. */
    public const OPERATIONS = [
        'addOrder'      => 'OrderMutation.addOrder',
        'updateOrder'   => 'OrderMutation.updateOrder',
        'ordersFetcher' => 'Query.ordersFetcher',
    ];

    private const OPERATION_LABELS = [
        'OrderMutation.updateOrder' => 'изменение заказов — orderMutation.updateOrder',
        'OrderMutation.addOrder'    => 'создание заказов — orderMutation.addOrder',
        'Query.ordersFetcher'       => 'чтение заказов — ordersFetcher',
    ];

    private const CACHE_TTL = 60;

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * @return array{state:string, code:?string, message:string, missing:string[], since:?string, last_checked_at:?string}
     */
    public static function get(int $tenantId): array
    {
        $default = [
            'state' => self::STATE_OK, 'code' => null, 'message' => '',
            'missing' => [], 'since' => null, 'last_checked_at' => null,
        ];

        $raw = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'sr_health')
            ->first();

        $decoded = $raw && is_string($raw->value) && $raw->value !== '' ? json_decode($raw->value, true) : null;

        return is_array($decoded) ? array_merge($default, $decoded) : $default;
    }

    /**
     * Known blocking problem for a given operation, or null if the operation may be tried.
     *
     * @return array{code:string, message:string}|null
     */
    public static function blockingFor(int $tenantId, string $operation): ?array
    {
        $h = self::get($tenantId);

        if ($h['state'] === self::STATE_OK) {
            return null;
        }

        if ($h['state'] === self::STATE_PERMISSION_DENIED) {
            $key = self::OPERATIONS[$operation] ?? $operation;
            if (!in_array($key, $h['missing'], true)) {
                return null;
            }
        }

        return ['code' => (string) ($h['code'] ?? SrResult::PERMISSION_DENIED), 'message' => (string) $h['message']];
    }

    // ─── Write ────────────────────────────────────────────────────────────────

    public static function record(int $tenantId, string $operation, SrResult $result): void
    {
        $key = self::OPERATIONS[$operation] ?? $operation;
        $h   = self::get($tenantId);
        $new = $h;
        $now = now()->toIso8601String();

        if ($result->ok) {
            $new['missing'] = array_values(array_diff($h['missing'], [$key]));
            if ($h['state'] !== self::STATE_OK
                && ($h['state'] !== self::STATE_PERMISSION_DENIED || $new['missing'] === [])) {
                $new = array_merge($new, ['state' => self::STATE_OK, 'code' => null, 'message' => '', 'since' => null]);
            }
        } elseif (in_array($result->errorCode, [
            SrResult::PERMISSION_DENIED, SrResult::PERMISSION_DENIED_READ, SrResult::PERMISSION_DENIED_CREATE,
        ], true)) {
            $missing        = $result->restrictionKey() ?: $key;
            $new['missing'] = array_values(array_unique(array_merge($h['missing'], [$missing])));
            $new['state']   = self::STATE_PERMISSION_DENIED;
            $new['code']    = $result->errorCode;
            $new['message'] = $result->message;
            $new['since']   = $h['since'] ?: $now;
        } elseif (in_array($result->errorCode, [SrResult::AUTH_INVALID, SrResult::AUTH_FORBIDDEN_IP], true)) {
            $new = array_merge($new, [
                'state' => self::STATE_AUTH_FAILED, 'code' => $result->errorCode,
                'message' => $result->message, 'since' => $h['since'] ?: $now,
            ]);
        } elseif (in_array($result->errorCode, [SrResult::CONFIG_COMPANY, SrResult::CONFIG_INCOMPLETE], true)) {
            $new = array_merge($new, [
                'state' => self::STATE_CONFIG_ERROR, 'code' => $result->errorCode,
                'message' => $result->message, 'since' => $h['since'] ?: $now,
            ]);
        } else {
            return; // transient / business errors do not change integration health
        }

        // Write only on a real change: this runs on every SalesRender request.
        $comparable = static function (array $x): array {
            unset($x['last_checked_at']);
            return $x;
        };
        if ($comparable($new) === $comparable($h)) {
            return;
        }

        $new['last_checked_at'] = $now;
        TenantSetting::put($tenantId, 'sr_health', json_encode($new, JSON_UNESCAPED_UNICODE));
        self::flush($tenantId);
    }

    /** Reset after the token / company id is replaced in settings. */
    public static function reset(int $tenantId): void
    {
        TenantSetting::put($tenantId, 'sr_health', '');
        TenantSetting::put($tenantId, 'sr_check_ok_at', '');
        self::flush($tenantId);
    }

    public static function flush(int $tenantId): void
    {
        Cache::forget('sr_shared:' . $tenantId);
    }

    // ─── Request text for the call center ─────────────────────────────────────

    public static function requestText(int $tenantId): string
    {
        $h         = self::get($tenantId);
        $companyId = self::setting($tenantId, 'company_id_in_call_centre');
        $projectId = self::setting($tenantId, 'project_id_in_call_centr');

        $lines   = [];
        $lines[] = 'Здравствуйте! Нашей CRM нужен API-токен SalesRender с расширенными правами.';
        $lines[] = 'Компания (Company ID): ' . ($companyId ?: '—');
        if ($projectId) {
            $lines[] = 'Проект: ' . $projectId;
        }
        $lines[] = '';

        if ($h['state'] === self::STATE_AUTH_FAILED) {
            $lines[] = 'Сейчас SalesRender не принимает наш токен (недействителен, истёк или закрыт по IP).';
            $lines[] = 'Пришлите, пожалуйста, новый токен.';
        } else {
            // Ask for the whole set at once: SalesRender reports only the first missing right,
            // and several rounds of correspondence with the call center help nobody.
            $lines[] = 'Для работы интеграции токену нужны права на:';
            foreach (array_values(array_unique(array_merge($h['missing'], array_keys(self::OPERATION_LABELS)))) as $m) {
                $lines[] = ' • ' . (self::OPERATION_LABELS[$m] ?? $m)
                    . (in_array($m, $h['missing'], true) ? ' (сейчас отсутствует)' : '');
            }
            $lines[] = 'Ошибка SalesRender: FIELD_RESTRICTED_BY_ROLE';
            $lines[] = '';
            $lines[] = 'Что нужно сделать: создайте токен с ролью, у которой НЕ включены ограничения '
                . '«Restrictions on order editing» (редактирование заказов) и «Viewing restrictions» в разделе Orders, '
                . 'и не скрыты поля типа «Phone».';
        }

        $ip = config('salesrender.outbound_ip');
        if ($ip) {
            $lines[] = 'Если у роли задан список разрешённых IP — добавьте адрес нашего сервера: ' . $ip . '.';
        }
        $lines[] = 'Новый токен пришлите нам — укажем его в настройках интеграции.';

        return implode("\n", $lines);
    }

    // ─── Shared Inertia payload ───────────────────────────────────────────────

    /**
     * Null when SalesRender is not used or everything is fine and nothing needs attention.
     *
     * @return array<string, mixed>|null
     */
    public static function shared(int $tenantId): ?array
    {
        return Cache::remember('sr_shared:' . $tenantId, self::CACHE_TTL, function () use ($tenantId) {
            if (self::setting($tenantId, 'sr_enabled') !== '1') {
                return null;
            }

            $h      = self::get($tenantId);
            $counts = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('sr_sync_state', ['failed', 'blocked', 'conflict'])
                ->selectRaw('sr_sync_state, COUNT(*) as c')
                ->groupBy('sr_sync_state')
                ->pluck('c', 'sr_sync_state')
                ->all();

            $problem = [
                'failed'   => (int) ($counts['failed'] ?? 0),
                'blocked'  => (int) ($counts['blocked'] ?? 0),
                'conflict' => (int) ($counts['conflict'] ?? 0),
            ];
            $problem['total'] = array_sum($problem);

            if ($h['state'] === self::STATE_OK && $problem['total'] === 0) {
                return null;
            }

            return [
                'health' => [
                    'state'   => $h['state'],
                    'message' => $h['message'],
                    'missing' => $h['missing'],
                    'since'   => $h['since'],
                ],
                'problem_orders' => $problem,
                'push_enabled'   => self::setting($tenantId, 'sr_push_enabled') === '1',
                'request_text'   => $h['state'] !== self::STATE_OK ? self::requestText($tenantId) : null,
            ];
        });
    }

    public static function setting(int $tenantId, string $key): string
    {
        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row ? (string) $row->value : '';
    }
}
