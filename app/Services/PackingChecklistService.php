<?php

namespace App\Services;

use App\Models\MailBatch;
use App\Models\Order;

class PackingChecklistService
{
    /**
     * Rows for the Belpost packing screen.
     * Selected batch → that batch's orders; otherwise eligible «Отправить» fallback.
     *
     * @param  MailBatch|null  $batch
     * @param  iterable        $eligibleFallback  Orders or order-like arrays
     * @return array<int, array{id:int, full_name:string, items:array, items_label:string, sum:float}>
     */
    public function rowsForBelpost(?MailBatch $batch, $eligibleFallback): array
    {
        if ($batch !== null) {
            $orders = $batch->orders()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'full_name', 'goods', 'quantities', 'prices']);

            return $this->rowsFromOrders($orders);
        }

        return $this->rowsFromOrders($eligibleFallback);
    }

    /**
     * Eligible Europochta «Отправить» without a track — same set as EvropostController::index.
     *
     * @return array<int, array{id:int, full_name:string, items:array, items_label:string, sum:float}>
     */
    public function rowsForEuropochta(): array
    {
        $orders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'europochta')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'full_name', 'goods', 'quantities', 'prices']);

        return $this->rowsFromOrders($orders);
    }

    /**
     * Eligible courier «Отправить» — same set as CourierController::index.
     * Courier is not postal: do not filter track_number.
     *
     * @return array<int, array{id:int, full_name:string, items:array, items_label:string, sum:float}>
     */
    public function rowsForCourier(): array
    {
        $orders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'courier')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'full_name', 'goods', 'quantities', 'prices']);

        return $this->rowsFromOrders($orders);
    }

    /**
     * @param  array<int, array{sum:float}>  $rows
     */
    public function total(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += isset($row['sum']) ? (float) $row['sum'] : 0.0;
        }

        return round($sum, 2);
    }

    /**
     * @param  iterable  $orders
     * @return array<int, array{id:int, full_name:string, items:array, items_label:string, sum:float}>
     */
    public function rowsFromOrders($orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = $this->rowFromOrder($order);
        }

        return $rows;
    }

    /**
     * @param  \App\Models\Order|array|object  $order
     * @return array{id:int, full_name:string, items:array<int, array{name:string, qty:int}>, items_label:string, sum:float}
     */
    public function rowFromOrder($order): array
    {
        $goods  = $this->asList($this->orderField($order, 'goods', []));
        $qtys   = $this->asList($this->orderField($order, 'quantities', []));
        $prices = $this->asList($this->orderField($order, 'prices', []));

        $items  = [];
        $labels = [];
        $sum    = 0.0;
        $count  = count($goods);

        for ($i = 0; $i < $count; $i++) {
            $name = trim((string) $goods[$i]);
            if ($name === '') {
                continue;
            }

            $qty = isset($qtys[$i]) ? (int) $qtys[$i] : 1;
            if ($qty < 1) {
                $qty = 1;
            }

            $price = isset($prices[$i]) ? (float) $prices[$i] : 0.0;

            $items[]  = ['name' => $name, 'qty' => $qty];
            $labels[] = $name . ' × ' . $qty;
            $sum     += $price * $qty;
        }

        return [
            'id'          => (int) $this->orderField($order, 'id', 0),
            'full_name'   => (string) $this->orderField($order, 'full_name', ''),
            'items'       => $items,
            'items_label' => implode(' · ', $labels),
            'sum'         => round($sum, 2),
        ];
    }

    /**
     * @param  \App\Models\Order|array|object  $order
     * @param  mixed                           $default
     * @return mixed
     */
    private function orderField($order, string $key, $default = null)
    {
        if (is_array($order)) {
            return array_key_exists($key, $order) ? $order[$key] : $default;
        }

        if (is_object($order) && isset($order->{$key})) {
            return $order->{$key};
        }

        return $default;
    }

    /**
     * @param  mixed  $value
     * @return array
     */
    private function asList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }
}
