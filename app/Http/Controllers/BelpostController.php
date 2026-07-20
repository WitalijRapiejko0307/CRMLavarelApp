<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadBelpostPdfJob;
use App\Models\MailBatch;
use App\Models\Order;
use App\Rules\FullNameThreeParts;
use App\Services\BelpostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BelpostController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /belpost
     * Main Belpost batch management page.
     */
    public function index(): Response
    {
        $batches = MailBatch::orderByDesc('created_at')->get();

        $eligibleOrders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'belpost')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->get(['id', 'full_name', 'city', 'street', 'building', 'housing', 'apartment', 'phone', 'goods', 'quantities', 'prices']);

        return Inertia::render('Belpost/Batch', [
            'batches'        => $batches,
            'eligibleOrders' => $eligibleOrders,
            'deliveryTypes'  => MailBatch::DELIVERY_TYPES,
        ]);
    }

    // ─── Batch CRUD ───────────────────────────────────────────────────────────

    /**
     * POST /belpost/batches
     * Create a new batch list on Belpost and persist it.
     *
     * Body: { type: string, who_pays: 'Покупатель'|'Продавец' }
     * For ecommerce_light / ecommerce_optima types, who_pays is forced to 'Продавец'.
     */
    public function createBatch(Request $request): JsonResponse
    {
        $request->validate([
            'type'     => ['required', 'string', 'max:100'],
            'who_pays' => ['required', 'string', 'in:Покупатель,Продавец'],
        ]);

        $type    = $request->input('type');
        $whoPays = $request->input('who_pays');

        // Seller-only types enforce 'Продавец'
        if (in_array($type, MailBatch::SELLER_ONLY_TYPES, true)) {
            if ($whoPays !== 'Продавец') {
                return response()->json([
                    'success' => false,
                    'message' => 'Для типа «' . ($type) . '» доставку оплачивает только Продавец.',
                ], 422);
            }
        }

        try {
            $service = new BelpostService(Auth::user()->tenant_id);
            $batch   = $service->createList($type, $whoPays);

            return response()->json([
                'success' => true,
                'batch'   => $batch,
                'message' => "Партия #{$batch->batch_id} создана",
            ]);
        } catch (\Throwable $e) {
            Log::error('BelpostController::createBatch error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания партии: ' . $e->getMessage(),
            ], 422);
        }
    }

    // ─── Item processing ──────────────────────────────────────────────────────

    /**
     * POST /belpost/batches/{batch}/items
     * Process a single order: resolve address + create item on Belpost.
     *
     * Body: { order_id: int, belpost_address_id?: string }
     */
    public function processOrder(Request $request, MailBatch $batch): JsonResponse
    {
        $request->validate([
            'order_id'           => ['required', 'integer'],
            'belpost_address_id' => ['nullable', 'string'],
        ]);

        if ($batch->status !== MailBatch::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Партия уже закрыта или в другом статусе',
            ], 422);
        }

        $orderId = (int) $request->input('order_id');
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
        }

        $fioRule = new FullNameThreeParts();
        if (!$fioRule->passes('full_name', $order->full_name)) {
            return response()->json([
                'success' => false,
                'message' => $fioRule->message(),
            ], 422);
        }

        try {
            $service = new BelpostService(Auth::user()->tenant_id);
            $result  = $service->createItem($batch, $order, $request->input('belpost_address_id'));

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('BelpostController::processOrder error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success'       => false,
                'error'         => 'exception',
                'error_message' => $e->getMessage(),
            ], 422);
        }
    }

    // ─── Commit ───────────────────────────────────────────────────────────────

    /**
     * POST /belpost/batches/{batch}/commit
     * Commit the batch and dispatch PDF download job.
     */
    public function commit(MailBatch $batch): JsonResponse
    {
        if ($batch->status !== MailBatch::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Партия не в статусе «Черновик»',
            ], 422);
        }

        try {
            $service      = new BelpostService(Auth::user()->tenant_id);
            $idToDownload = $service->commitActiveList($batch);

            DownloadBelpostPdfJob::dispatch($batch->id, Auth::user()->tenant_id);

            return response()->json([
                'success'        => true,
                'id_to_download' => $idToDownload,
                'message'        => 'Партия зафиксирована. PDF готовится в фоне.',
            ]);
        } catch (\Throwable $e) {
            Log::error('BelpostController::commit error', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка commit: ' . $e->getMessage(),
            ], 422);
        }
    }

    // ─── Polling + Download ───────────────────────────────────────────────────

    /**
     * GET /api/belpost/batches/{batch}/status
     * Polling endpoint: returns current batch status for frontend.
     */
    public function batchStatus(MailBatch $batch): JsonResponse
    {
        return response()->json([
            'status'         => $batch->status,
            'pdf_ready'      => $batch->isPdfReady(),
            'id_to_download' => $batch->id_to_download,
            'error_message'  => $batch->error_message,
        ]);
    }

    /**
     * GET /belpost/batches/{batch}/pdf
     * Stream the stored ZIP archive as a download.
     */
    public function downloadPdf(MailBatch $batch)
    {
        if (!$batch->isPdfReady()) {
            return back()->with('error', 'PDF ещё не готов');
        }

        $path = $batch->pdf_path;

        if (!Storage::exists($path)) {
            return back()->with('error', 'Файл не найден на сервере');
        }

        return Storage::download($path, "belpost-{$batch->batch_id}.zip");
    }
}
