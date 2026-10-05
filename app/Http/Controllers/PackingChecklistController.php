<?php

namespace App\Http\Controllers;

use App\Models\MailBatch;
use App\Models\Order;
use App\Services\PackingChecklistService;
use App\Support\AppPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PackingChecklistController extends Controller
{
    /** @var PackingChecklistService */
    private PackingChecklistService $service;

    public function __construct(PackingChecklistService $service)
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
        $this->service = $service;
    }

    /**
     * GET /belpost/packing.pdf?batch={id optional}
     * Batch belonging to the tenant → that batch's orders; else eligible Belpost «Отправить» without track.
     */
    public function belpost(Request $request): Response
    {
        $batch = null;
        $batchId = (int) $request->query('batch');
        if ($batchId > 0) {
            $batch = MailBatch::query()->find($batchId);
        }

        $eligible = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'belpost')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'full_name', 'goods', 'quantities', 'prices']);

        $rows = $this->service->rowsForBelpost($batch, $eligible);

        return $this->downloadPdf($rows);
    }

    /**
     * GET /europochta/packing.pdf
     * Eligible Europochta «Отправить» without track. Does not call Evropost APIs.
     */
    public function europochta(): Response
    {
        $rows = $this->service->rowsForEuropochta();

        return $this->downloadPdf($rows);
    }

    /**
     * GET /courier/packing.pdf
     * Eligible courier «Отправить». Does not call postal or Telegram APIs.
     */
    public function courier(): Response
    {
        $rows = $this->service->rowsForCourier();

        return $this->downloadPdf($rows);
    }

    /**
     * @param  array<int, array{id:int, full_name:string, items:array, items_label:string, sum:float}>  $rows
     */
    private function downloadPdf(array $rows): Response
    {
        $count = count($rows);
        $total = $this->service->total($rows);

        return AppPdf::loadView('pdf.packing-checklist', [
            'rows'  => $rows,
            'total' => $total,
            'count' => $count,
            'title' => $count > 0
                ? $count . ' посылок · что упаковать'
                : 'Нет посылок для сборки',
        ])->setPaper('a4')->download('packing-checklist.pdf');
    }
}
