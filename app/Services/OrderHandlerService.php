<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderHandlerService
{
    /**
     * @param  Collection<int, int>|array<int, int>  $orderIds
     * @return array<int, array<int, array{user_id: int, name: string, last_status: string, last_at: string}>>
     */
    public function handlersForOrders(Collection|array $orderIds): array
    {
        $ids = collect($orderIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $latestPerUser = OrderStatusHistory::query()
            ->select([
                'order_id',
                'user_id',
                DB::raw('MAX(created_at) as last_at'),
            ])
            ->whereIn('order_id', $ids)
            ->whereIn('to_status', Order::WORK_STATUSES)
            ->whereNotNull('user_id')
            ->groupBy('order_id', 'user_id');

        $rows = DB::table('order_status_history as h')
            ->joinSub($latestPerUser, 'latest', function ($join) {
                $join->on('h.order_id', '=', 'latest.order_id')
                    ->on('h.user_id', '=', 'latest.user_id')
                    ->on('h.created_at', '=', 'latest.last_at');
            })
            ->join('users', 'users.id', '=', 'h.user_id')
            ->whereIn('h.to_status', Order::WORK_STATUSES)
            ->select([
                'h.order_id',
                'h.user_id',
                'h.to_status as last_status',
                'h.created_at as last_at',
                'users.name',
            ])
            ->orderBy('h.created_at')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->order_id][] = [
                'user_id'     => (int) $row->user_id,
                'name'        => $row->name,
                'last_status' => $row->last_status,
                'last_at'     => $row->last_at,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{user_id: int, name: string, last_status: string, last_at: string}>
     */
    public function handlersForOrder(int $orderId): array
    {
        return $this->handlersForOrders([$orderId])[$orderId] ?? [];
    }
}
