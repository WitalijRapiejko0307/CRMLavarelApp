<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Support\SrResult;
use App\Support\SrStatusMap;

/**
 * "Check connection" in settings: safe probes that change no real data.
 *
 * Permission probes rely on SalesRender checking the role restriction BEFORE it touches data:
 * a token without the right gets FIELD_RESTRICTED_BY_ROLE, a token with the right gets an
 * ordinary "order not found"-style answer for the non-existent id 0.
 */
class SrConnectionCheckService
{
    /**
     * @return array{ok: bool, checks: list<array{key: string, label: string, status: string, message: string}>}
     */
    public function run(int $tenantId): array
    {
        $token   = SrHealthService::setting($tenantId, 'api_token_call_centr');
        $company = SrHealthService::setting($tenantId, 'company_id_in_call_centre');
        $project = SrHealthService::setting($tenantId, 'project_id_in_call_centr');

        if ($token === '' || $company === '') {
            $r = SrResult::failure(SrResult::CONFIG_INCOMPLETE);
            SrHealthService::record($tenantId, 'ordersFetcher', $r);

            return ['ok' => false, 'checks' => [$this->check('config', 'Настройки', 'fail', $r->message)]];
        }

        $service = new SalesRenderService($token, $company, $project, $tenantId);
        $checks  = [];

        // 1. Read orders (also validates token and company id).
        $read = $service->fetchOrderResult('0');
        $readOk = $read->ok || $read->errorCode === SrResult::ORDER_NOT_FOUND;
        $checks[] = $this->check('read', 'Чтение заказов (ordersFetcher)', $readOk ? 'ok' : 'fail', $readOk ? 'Есть доступ' : $read->message);

        // 2. Update orders: only meaningful when the token itself is accepted.
        if ($readOk || !in_array($read->errorCode, [SrResult::AUTH_INVALID, SrResult::AUTH_FORBIDDEN_IP, SrResult::CONFIG_COMPANY], true)) {
            $update   = $service->updateOrder('0', []);
            $denied   = in_array($update->errorCode, [SrResult::PERMISSION_DENIED, SrResult::AUTH_INVALID, SrResult::AUTH_FORBIDDEN_IP, SrResult::CONFIG_COMPANY], true);
            $updateOk = !$denied && !$update->isTransient();

            if ($updateOk) {
                // Any non-permission answer proves the right exists: clear the stored "missing" mark.
                SrHealthService::record($tenantId, 'updateOrder', SrResult::success([]));
            }

            $checks[] = $this->check(
                'update',
                'Изменение заказов (updateOrder)',
                $updateOk ? 'ok' : 'fail',
                $updateOk ? 'Есть доступ' : ($update->isTransient() ? 'Не удалось проверить: SalesRender недоступен' : $update->message)
            );
        } else {
            $updateOk = false;
            $checks[] = $this->check('update', 'Изменение заказов (updateOrder)', 'fail', 'Не проверено: токен не принят');
        }

        // 3. Status names used for status transfer.
        if ($readOk) {
            $statuses = $service->fetchStatuses();

            if ($statuses instanceof SrResult) {
                $checks[] = $this->check('statuses', 'Статусы SalesRender', 'warn', 'Не удалось получить список статусов: ' . $statuses->message);
            } else {
                $missing = array_values(array_filter(array_unique(array_values(SrStatusMap::OUTGOING)), function ($name) use ($statuses) {
                    return !isset($statuses[$name]);
                }));

                $checks[] = $missing === []
                    ? $this->check('statuses', 'Статусы SalesRender', 'ok', 'Все статусы для передачи найдены')
                    : $this->check('statuses', 'Статусы SalesRender', 'warn', 'В SalesRender нет статусов: ' . implode(', ', $missing) . '. Эти статусы из CRM передаваться не будут.');
            }

            // 4. Order fields used for name / phone.
            $fields = $service->fetchOrderFields();
            if ($fields->ok) {
                $ids = array_map(function ($f) {
                    return (string) ($f['id'] ?? '');
                }, $fields->data['data']['orderFieldsFetcher']['fields'] ?? []);

                $map     = json_decode(SrHealthService::setting($tenantId, 'sr_field_map'), true);
                $wanted  = [
                    'phone' => is_array($map) && !empty($map['phone_field_id']) ? (string) $map['phone_field_id'] : 'phone',
                    'name'  => is_array($map) && !empty($map['name_field_id']) ? (string) $map['name_field_id'] : 'name',
                ];
                $absent = array_keys(array_filter($wanted, function ($id) use ($ids) {
                    return !in_array($id, $ids, true);
                }));

                $labels = ['phone' => 'телефон', 'name' => 'ФИО'];
                $checks[] = $absent === []
                    ? $this->check('fields', 'Поля заказа', 'ok', 'Поля телефона и ФИО найдены')
                    : $this->check('fields', 'Поля заказа', 'warn', 'В проекте SalesRender нет поля: '
                        . implode(', ', array_map(function ($k) use ($labels) {
                            return $labels[$k];
                        }, $absent)) . '. Эти данные отправляться не будут (SalesRender молча их игнорирует).');
            } else {
                $checks[] = $this->check('fields', 'Поля заказа', 'warn', 'Не удалось получить поля: ' . $fields->message);
            }
        }

        $ok = $readOk && $updateOk;

        TenantSetting::put($tenantId, 'sr_check_ok_at', $ok ? now()->toIso8601String() : '');
        SrHealthService::flush($tenantId);

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * Blocked orders go back to the queue (comparison with SalesRender protects newer data).
     */
    public function requeueBlocked(int $tenantId): int
    {
        $count = 0;
        $delay = 0;

        Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('sr_sync_state', ['blocked', 'failed', 'retrying'])
            ->whereNotNull('sr_pending_changes')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$count, &$delay) {
                foreach ($orders as $order) {
                    $order->forceFill(['sr_sync_state' => 'pending', 'sr_push_attempts' => 0])->saveQuietly();
                    \App\Jobs\PushOrderToSalesRenderJob::dispatch($order->id, false, true)->delay(now()->addSeconds($delay));
                    $delay += 1; // 1 s between orders keeps the request rate low
                    $count++;
                }
            });

        SrHealthService::flush($tenantId);

        return $count;
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    private function check(string $key, string $label, string $status, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    }
}
