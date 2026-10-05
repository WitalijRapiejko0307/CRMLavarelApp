<?php

namespace App\Services;

use App\Exceptions\TelegramException;
use App\Mail\DailyDigestMail;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DailyDigestService
{
    public const TIMEZONE = 'Europe/Minsk';

    private const ACCEPTED_STATUSES = ['Подтвержден', 'Отправить'];
    private const SALES_STATUSES = ['Завершен', 'Посчитан'];
    private const PRODUCT_LIMIT = 15;

    /**
     * @return array{
     *     date: string,
     *     date_label: string,
     *     leads: int,
     *     accepted: int,
     *     sales: int,
     *     products: list<array{name: string, qty: int, sum: float}>
     * }
     */
    public function snapshot(int $tenantId, ?Carbon $now = null): array
    {
        $now = $this->now($now);
        $today = $now->toDateString();

        $salesOrderIds = $this->distinctOrderIds($tenantId, $today, self::SALES_STATUSES);

        return [
            'date'       => $today,
            'date_label' => $now->format('d.m.Y'),
            'leads'      => $this->countLeads($tenantId, $today),
            'accepted'   => $this->countDistinctOrders($tenantId, $today, self::ACCEPTED_STATUSES),
            'sales'      => count($salesOrderIds),
            'products'   => $this->productRows($tenantId, $salesOrderIds),
        ];
    }

    /**
     * @param  array{
     *     date_label: string,
     *     leads: int,
     *     accepted: int,
     *     sales: int,
     *     products: list<array{name: string, qty: int, sum: float}>
     * }  $snapshot
     */
    public function format(array $snapshot): string
    {
        $lines = [
            'Сводка CRM за ' . $snapshot['date_label'],
            '',
            'Заявки: ' . $snapshot['leads'],
            'Принятые: ' . $snapshot['accepted'],
            'Продажи: ' . $snapshot['sales'],
            '',
            'Товары:',
        ];

        if (empty($snapshot['products'])) {
            $lines[] = 'Товаров нет';
        } else {
            foreach ($snapshot['products'] as $row) {
                $lines[] = '• ' . $row['name'] . ' × ' . $row['qty'] . ' — ' . $this->formatMoney($row['sum']) . ' BYN';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, skipped?: bool, error?: string, message?: string}
     */
    public function send(int $tenantId, bool $force = false, ?Carbon $now = null): array
    {
        app()->instance('current_tenant_id', $tenantId);
        $now = $this->now($now);
        $today = $now->toDateString();

        if (!$force) {
            if (TenantSetting::get('digest_enabled', '') !== '1') {
                return ['ok' => true, 'skipped' => true];
            }
            if ((string) TenantSetting::get('digest_last_sent_on', '') === $today) {
                return ['ok' => true, 'skipped' => true];
            }
        }

        if (!$this->hasChannel($tenantId)) {
            return [
                'ok'    => false,
                'error' => 'Укажите email или подключите Telegram',
            ];
        }

        $snapshot = $this->snapshot($tenantId, $now);
        $text     = $this->format($snapshot);

        $emailAttempted = false;
        $emailOk        = false;
        $tgAttempted    = false;
        $tgOk           = false;

        $email = trim((string) TenantSetting::get('digest_email', ''));
        if ($email !== '') {
            $emailAttempted = true;
            try {
                Mail::to($email)->send(new DailyDigestMail($snapshot['date_label'], $text));
                $emailOk = true;
            } catch (\Throwable $e) {
                Log::warning('Daily digest email failed', [
                    'tenant_id' => $tenantId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $telegram = TelegramService::forTenant($tenantId);
        if ($telegram !== null) {
            $tgAttempted = true;
            try {
                $telegram->sendMessage($text);
                $tgOk = true;
            } catch (TelegramException $e) {
                Log::warning('Daily digest telegram failed', [
                    'tenant_id' => $tenantId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if (!$emailAttempted && !$tgAttempted) {
            return [
                'ok'    => false,
                'error' => 'Укажите email или подключите Telegram',
            ];
        }

        if (!$emailOk && !$tgOk) {
            return [
                'ok'    => false,
                'error' => 'Не удалось отправить сводку',
            ];
        }

        TenantSetting::put($tenantId, 'digest_last_sent_on', $today);

        return [
            'ok'      => true,
            'message' => 'Сводка отправлена',
        ];
    }

    public function hasChannel(int $tenantId): bool
    {
        app()->instance('current_tenant_id', $tenantId);

        $email = trim((string) TenantSetting::get('digest_email', ''));
        if ($email !== '') {
            return true;
        }

        return TelegramService::forTenant($tenantId) !== null;
    }

    private function now(?Carbon $now): Carbon
    {
        return ($now ?? Carbon::now(self::TIMEZONE))->copy()->timezone(self::TIMEZONE);
    }

    private function countLeads(int $tenantId, string $today): int
    {
        $query = Order::withoutGlobalScopes()->where('tenant_id', $tenantId);
        Order::funnelBaseQuery($query);

        return $query->whereDate('created_at', $today)->count();
    }

    /**
     * @param  list<string>  $toStatuses
     */
    private function countDistinctOrders(int $tenantId, string $today, array $toStatuses): int
    {
        return (int) OrderStatusHistory::query()
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->whereDate('order_status_history.created_at', $today)
            ->whereIn('order_status_history.to_status', $toStatuses)
            ->selectRaw('COUNT(DISTINCT order_status_history.order_id) as cnt')
            ->value('cnt');
    }

    /**
     * @param  list<string>  $toStatuses
     * @return list<int>
     */
    private function distinctOrderIds(int $tenantId, string $today, array $toStatuses): array
    {
        return OrderStatusHistory::query()
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->whereDate('order_status_history.created_at', $today)
            ->whereIn('order_status_history.to_status', $toStatuses)
            ->distinct()
            ->pluck('order_status_history.order_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<array{name: string, qty: int, sum: float}>
     */
    private function productRows(int $tenantId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $orderIds)
            ->get(['goods', 'quantities', 'prices']);

        $agg = [];
        foreach ($orders as $order) {
            $goods = $order->goods ?? [];
            $qtys  = $order->quantities ?? [];
            $prices = $order->prices ?? [];
            $n = count($goods);
            for ($i = 0; $i < $n; $i++) {
                $name = trim((string) ($goods[$i] ?? ''));
                if ($name === '') {
                    continue;
                }
                $qty = (int) ($qtys[$i] ?? 0);
                if ($qty < 1) {
                    continue;
                }
                $price = (float) ($prices[$i] ?? 0);
                if (!isset($agg[$name])) {
                    $agg[$name] = ['name' => $name, 'qty' => 0, 'sum' => 0.0];
                }
                $agg[$name]['qty'] += $qty;
                $agg[$name]['sum'] += $price * $qty;
            }
        }

        $rows = array_values($agg);
        usort($rows, function (array $a, array $b) {
            if ($a['qty'] !== $b['qty']) {
                return $b['qty'] <=> $a['qty'];
            }

            return strcmp($a['name'], $b['name']);
        });

        return array_slice($rows, 0, self::PRODUCT_LIMIT);
    }

    private function formatMoney(float $sum): string
    {
        $rounded = round($sum, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (string) (int) round($rounded);
        }

        return number_format($rounded, 2, '.', '');
    }
}
