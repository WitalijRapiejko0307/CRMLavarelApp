<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\CallCenterOrderQuery;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    private const REFUSAL_STATUSES = ['Отказ', 'Отказ(Ошибка)'];

    private const NO_ANSWER_STATUSES = ['Недозвон', 'Недозвон1', 'Недозвон2'];

    /**
     * @return array{
     *     summary: array<string, int|float>,
     *     rows: array<int, array<string, mixed>>,
     *     filters: array<string, mixed>,
     *     canFilterTeam: bool,
     * }
     */
    public function forCallCenter(
        int $callCenterTenantId,
        User $user,
        string $tab,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $storeId = null,
        ?int $userId = null,
    ): array {
        $canFilterTeam = $user->tenant?->isCallCenter()
            && in_array($user->role, ['admin', 'manager'], true);

        $scopedUserId = $canFilterTeam ? $userId : $user->id;

        if ($tab === 'stores') {
            return $this->storesTab($callCenterTenantId, $scopedUserId, $canFilterTeam, $dateFrom, $dateTo, $storeId);
        }

        return $this->managersTab($callCenterTenantId, $scopedUserId, $canFilterTeam, $dateFrom, $dateTo, $userId);
    }

    /**
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>, filters: array<string, mixed>, canFilterTeam: bool}
     */
    private function managersTab(
        int $callCenterTenantId,
        ?int $scopedUserId,
        bool $canFilterTeam,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $filterUserId,
    ): array {
        $orderIds = $this->scopedOrderIds($callCenterTenantId, $scopedUserId, null, $dateFrom, $dateTo);

        if ($orderIds === []) {
            return $this->emptyResult($canFilterTeam, $dateFrom, $dateTo, $filterUserId, null);
        }

        $query = DB::table('order_status_history as h')
            ->join('users', 'users.id', '=', 'h.user_id')
            ->whereIn('h.order_id', $orderIds)
            ->whereIn('h.to_status', Order::WORK_STATUSES)
            ->where('users.tenant_id', $callCenterTenantId);

        $this->applyHistoryDateFilter($query, $dateFrom, $dateTo, 'h.created_at');

        if ($canFilterTeam && $filterUserId) {
            $query->where('h.user_id', $filterUserId);
        } elseif (!$canFilterTeam && $scopedUserId) {
            $query->where('h.user_id', $scopedUserId);
        }

        $rows = $query
            ->select([
                'h.user_id',
                'users.name',
                DB::raw('COUNT(*) as touches'),
                DB::raw("SUM(CASE WHEN h.to_status = 'Подтвержден' THEN 1 ELSE 0 END) as confirmed"),
                DB::raw("SUM(CASE WHEN h.to_status IN ('Отказ', 'Отказ(Ошибка)') THEN 1 ELSE 0 END) as refusals"),
                DB::raw("SUM(CASE WHEN h.to_status = 'Спам' THEN 1 ELSE 0 END) as spam"),
                DB::raw("SUM(CASE WHEN h.to_status IN ('Недозвон', 'Недозвон1', 'Недозвон2') THEN 1 ELSE 0 END) as no_answer"),
                DB::raw('COUNT(DISTINCT h.order_id) as unique_orders'),
            ])
            ->groupBy('h.user_id', 'users.name')
            ->orderByDesc('touches')
            ->get();

        $summary = [
            'touches'        => (int) $rows->sum('touches'),
            'confirmed'      => (int) $rows->sum('confirmed'),
            'refusals'       => (int) $rows->sum('refusals'),
            'spam'           => (int) $rows->sum('spam'),
            'no_answer'      => (int) $rows->sum('no_answer'),
            'unique_orders'  => (int) $rows->sum('unique_orders'),
        ];

        return [
            'summary'       => $summary,
            'rows'          => $rows->map(fn ($row) => [
                'user_id'       => (int) $row->user_id,
                'name'          => $row->name,
                'touches'       => (int) $row->touches,
                'confirmed'     => (int) $row->confirmed,
                'refusals'      => (int) $row->refusals,
                'spam'          => (int) $row->spam,
                'no_answer'     => (int) $row->no_answer,
                'unique_orders' => (int) $row->unique_orders,
            ])->all(),
            'filters'       => [
                'tab'        => 'managers',
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
                'user_id'    => $canFilterTeam ? $filterUserId : null,
                'store_id'   => null,
            ],
            'canFilterTeam' => $canFilterTeam,
        ];
    }

    /**
     * @return array{summary: array<string, int|float>, rows: array<int, array<string, mixed>>, filters: array<string, mixed>, canFilterTeam: bool}
     */
    private function storesTab(
        int $callCenterTenantId,
        ?int $scopedUserId,
        bool $canFilterTeam,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $storeId,
    ): array {
        $ordersQuery = CallCenterOrderQuery::forTenant($callCenterTenantId)
            ->select('orders.id', 'orders.tenant_id');

        $this->applyOrderDateFilter($ordersQuery, $dateFrom, $dateTo);

        if ($storeId) {
            $ordersQuery->where('orders.tenant_id', $storeId);
        }

        if ($scopedUserId) {
            $ordersQuery->whereExists(function ($sub) use ($scopedUserId) {
                $sub->select(DB::raw(1))
                    ->from('order_status_history as h')
                    ->whereColumn('h.order_id', 'orders.id')
                    ->where('h.user_id', $scopedUserId);
            });
        }

        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            return $this->emptyResult($canFilterTeam, $dateFrom, $dateTo, null, $storeId, 'stores');
        }

        $orderIds = $orders->pluck('id')->all();
        $leadsByStore = $orders->groupBy('tenant_id')->map->count();

        $historyQuery = DB::table('order_status_history as h')
            ->join('orders', 'orders.id', '=', 'h.order_id')
            ->whereIn('h.order_id', $orderIds)
            ->whereIn('h.to_status', Order::WORK_STATUSES);

        $this->applyHistoryDateFilter($historyQuery, $dateFrom, $dateTo, 'h.created_at');

        $historyRows = $historyQuery
            ->select([
                'orders.tenant_id',
                'h.order_id',
                'h.to_status',
            ])
            ->get()
            ->groupBy('tenant_id');

        $storeNames = DB::table('tenants')
            ->whereIn('id', $leadsByStore->keys())
            ->pluck('name', 'id');

        $rows = [];

        foreach ($leadsByStore as $tenantId => $leads) {
            $events = $historyRows->get($tenantId, collect());
            $confirmedOrders = $events->where('to_status', 'Подтвержден')->pluck('order_id')->unique()->count();
            $refusals = $events->whereIn('to_status', self::REFUSAL_STATUSES)->count();
            $spam = $events->where('to_status', 'Спам')->count();
            $noAnswer = $events->whereIn('to_status', self::NO_ANSWER_STATUSES)->count();
            $conversion = $leads > 0 ? round($confirmedOrders / $leads * 100, 1) : 0.0;

            $rows[] = [
                'store_id'    => (int) $tenantId,
                'name'        => $storeNames[$tenantId] ?? '—',
                'leads'       => (int) $leads,
                'confirmed'   => (int) $confirmedOrders,
                'refusals'    => (int) $refusals,
                'spam'        => (int) $spam,
                'no_answer'   => (int) $noAnswer,
                'conversion'  => $conversion,
            ];
        }

        usort($rows, fn ($a, $b) => $b['leads'] <=> $a['leads']);

        $summary = [
            'leads'      => (int) array_sum(array_column($rows, 'leads')),
            'confirmed'  => (int) array_sum(array_column($rows, 'confirmed')),
            'refusals'   => (int) array_sum(array_column($rows, 'refusals')),
            'spam'       => (int) array_sum(array_column($rows, 'spam')),
            'no_answer'  => (int) array_sum(array_column($rows, 'no_answer')),
            'conversion' => ($totalLeads = array_sum(array_column($rows, 'leads'))) > 0
                ? round(array_sum(array_column($rows, 'confirmed')) / $totalLeads * 100, 1)
                : 0.0,
        ];

        return [
            'summary'       => $summary,
            'rows'          => $rows,
            'filters'       => [
                'tab'        => 'stores',
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
                'user_id'    => null,
                'store_id'   => $storeId,
            ],
            'canFilterTeam' => $canFilterTeam,
        ];
    }

    /**
     * @return list<int>
     */
    private function scopedOrderIds(
        int $callCenterTenantId,
        ?int $scopedUserId,
        ?int $storeId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $query = CallCenterOrderQuery::forTenant($callCenterTenantId)->select('orders.id');

        $this->applyOrderDateFilter($query, $dateFrom, $dateTo);

        if ($storeId) {
            $query->where('orders.tenant_id', $storeId);
        }

        if ($scopedUserId) {
            $query->whereExists(function ($sub) use ($scopedUserId) {
                $sub->select(DB::raw(1))
                    ->from('order_status_history as h')
                    ->whereColumn('h.order_id', 'orders.id')
                    ->where('h.user_id', $scopedUserId);
            });
        }

        return $query->pluck('id')->all();
    }

    private function applyOrderDateFilter($query, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom) {
            $query->whereDate('orders.created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('orders.created_at', '<=', $dateTo);
        }
    }

    private function applyHistoryDateFilter($query, ?string $dateFrom, ?string $dateTo, string $column): void
    {
        if ($dateFrom) {
            $query->whereDate($column, '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate($column, '<=', $dateTo);
        }
    }

    /**
     * @return array{summary: array<string, int|float>, rows: array<int, mixed>, filters: array<string, mixed>, canFilterTeam: bool}
     */
    private function emptyResult(
        bool $canFilterTeam,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $userId,
        ?int $storeId,
        string $tab = 'managers',
    ): array {
        $summary = $tab === 'stores'
            ? ['leads' => 0, 'confirmed' => 0, 'refusals' => 0, 'spam' => 0, 'no_answer' => 0, 'conversion' => 0.0]
            : ['touches' => 0, 'confirmed' => 0, 'refusals' => 0, 'spam' => 0, 'no_answer' => 0, 'unique_orders' => 0];

        return [
            'summary'       => $summary,
            'rows'          => [],
            'filters'       => [
                'tab'       => $tab,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'user_id'   => $canFilterTeam ? $userId : null,
                'store_id'  => $storeId,
            ],
            'canFilterTeam' => $canFilterTeam,
        ];
    }
}
