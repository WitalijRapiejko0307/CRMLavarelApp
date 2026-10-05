<?php

namespace App\Services;

use App\Models\Order;
use App\Support\AppPdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CourierSheetService
{
    /** @var PackingChecklistService */
    private $packing;

    public function __construct(PackingChecklistService $packing)
    {
        $this->packing = $packing;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Order $order): array
    {
        $row = $this->packing->rowFromOrder($order);

        $createdAt = $order->created_at
            ? Carbon::parse($order->created_at)->timezone('Europe/Minsk')->format('d.m.Y')
            : '';

        $goods  = is_array($order->goods) ? array_values($order->goods) : [];
        $qtys   = is_array($order->quantities) ? array_values($order->quantities) : [];
        $prices = is_array($order->prices) ? array_values($order->prices) : [];

        $items = [];
        foreach ($goods as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $qty = isset($qtys[$i]) ? (int) $qtys[$i] : 1;
            if ($qty < 1) {
                $qty = 1;
            }

            $price = isset($prices[$i]) ? (float) $prices[$i] : 0.0;

            $items[] = [
                'name'  => $name,
                'qty'   => $qty,
                'price' => $price,
                'sum'   => round($price * $qty, 2),
            ];
        }

        return [
            'order_id'   => $order->id,
            'date'       => $createdAt,
            'full_name'  => (string) $order->full_name,
            'phone'      => (string) $order->phone,
            'address'    => $order->full_address,
            'items'      => $items,
            'total'      => $row['sum'],
            'comment'    => (string) ($order->comment ?? ''),
            'upsell'     => (string) ($order->upsell ?? ''),
            'cross_sell' => (string) ($order->cross_sell ?? ''),
        ];
    }

    public function pdfBinary(Order $order): string
    {
        return AppPdf::loadView('pdf.courier-sheet', $this->viewData($order))
            ->setPaper('a4')
            ->output();
    }

    public function download(Order $order): Response
    {
        return AppPdf::loadView('pdf.courier-sheet', $this->viewData($order))
            ->setPaper('a4')
            ->download('courier-' . $order->id . '.pdf');
    }
}
