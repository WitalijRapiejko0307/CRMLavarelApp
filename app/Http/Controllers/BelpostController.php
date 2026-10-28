<?php

namespace App\Http\Controllers;

use App\Exceptions\BelpostLabelException;
use App\Models\BelpostLabelLog;
use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Rules\BelarusPhone;
use App\Rules\FullNameTwoParts;
use App\Services\BelpostLabelLogger;
use App\Services\BelpostLabelService;
use App\Services\BelpostPartialReceiptOpisService;
use App\Services\BelpostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BelpostController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /belpost
     * Main Belpost batch management page.
     */
    public function index(Request $request): Response
    {
        $batches = MailBatch::orderByDesc('created_at')->get();

        $eligibleOrders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'belpost')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->get(['id', 'full_name', 'city', 'street', 'building', 'housing', 'apartment', 'phone', 'goods', 'quantities', 'prices', 'poste_restante']);

        $eligibleOrders = $this->withWeightHints($eligibleOrders);

        $batchOrders = Order::whereIn('mail_batch_id', $batches->pluck('id'))
            ->orderBy('status_changed_at')
            ->get([
                'id', 'mail_batch_id', 'full_name', 'phone', 'city', 'street', 'building',
                'track_number', 'status_changed_at', 'goods', 'quantities', 'prices',
            ])
            ->groupBy('mail_batch_id');

        return Inertia::render('Belpost/Batch', [
            'batches'          => $batches,
            'eligibleOrders'   => $eligibleOrders,
            'deliveryTypes'    => MailBatch::DELIVERY_TYPES,
            'batchOrders'      => $batchOrders->map(fn ($group) => $group->values())->all(),
            'selectedBatchId'  => ($batchId = (int) $request->query('batch')) > 0 ? $batchId : null,
            'labelSizes'       => MailBatch::LABEL_SIZES,
            'defaultLabelSize' => TenantSetting::get('belpost_label_size', '150x100'),
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
            'type'               => ['required', 'string', 'max:100'],
            'who_pays'           => ['required', 'string', 'in:Покупатель,Продавец'],
            'is_partial_receipt' => ['sometimes', 'boolean'],
        ]);

        $type    = $request->input('type');
        $whoPays = $request->input('who_pays');
        $isPartialReceipt = $request->boolean('is_partial_receipt');

        if ($isPartialReceipt && !in_array($type, MailBatch::PARTIAL_RECEIPT_TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => '«Получение части вложения» доступно только для E-commerce Стандарт, Элит и Экспресс.',
            ], 422);
        }

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
            $batch   = $service->createList($type, $whoPays, $isPartialReceipt);

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
     * POST /belpost/batches/{batch}/items/{order}/remove
     * Unlink a draft batch order from CRM. Does not call Belpost (no delete-item API).
     */
    public function removeOrder(MailBatch $batch, Order $order): JsonResponse
    {
        if ($batch->isBelpostCommitted()) {
            return response()->json([
                'success' => false,
                'message' => 'Партия уже сформирована на Белпочте',
            ], 422);
        }

        if ((int) $order->mail_batch_id !== (int) $batch->id) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ не найден в этой партии',
            ], 404);
        }

        if (filled($order->track_number)) {
            return response()->json([
                'success' => false,
                'message' => 'Заказ уже оформлен на Белпочте',
            ], 422);
        }

        $payload = ['mail_batch_id' => null];

        if ($order->status === 'Оформлен') {
            $payload['status'] = 'Отправить';
        }

        $order->update($payload);

        return response()->json(['success' => true]);
    }

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

        if ($batch->isBelpostCommitted()) {
            return response()->json([
                'success' => false,
                'message' => 'Партия уже сформирована на Белпочте или идёт скачивание PDF',
            ], 422);
        }

        $orderId = (int) $request->input('order_id');
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Заказ не найден'], 404);
        }

        $fioRule = new FullNameTwoParts();
        if (!$fioRule->passes('full_name', $order->full_name)) {
            return response()->json([
                'success' => false,
                'message' => $fioRule->message(),
            ], 422);
        }

        $phoneRule = new BelarusPhone();
        if (!$phoneRule->passes('phone', $order->phone ?? '')) {
            return response()->json([
                'success' => false,
                'message' => $phoneRule->message(),
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
     * Commit the batch on Belpost (does not trigger PDF download).
     */
    public function commit(MailBatch $batch): JsonResponse
    {
        if ($batch->isBelpostCommitted()) {
            return response()->json([
                'success' => false,
                'message' => 'Партия уже сформирована на Белпочте',
            ], 422);
        }

        if (!$batch->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'В партии нет оформленных бланков',
            ], 422);
        }

        try {
            $service      = new BelpostService(Auth::user()->tenant_id);
            $service->commitActiveList($batch);

            $batch->update([
                'belpost_committed' => true,
                'status'            => MailBatch::STATUS_COMMITTED,
            ]);

            return response()->json([
                'success'           => true,
                'belpost_committed' => true,
                'message'           => 'Партия сформирована на Белпочте',
            ]);
        } catch (\Throwable $e) {
            Log::error('BelpostController::commit error', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка commit: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /belpost/batches/{batch}/download-partial-receipt-opis
     * Attachment inventory docx (one file or zip) for partial-receipt batches.
     *
     * @return \Illuminate\Http\Response|JsonResponse
     */
    public function downloadPartialReceiptOpis(MailBatch $batch)
    {
        $logger    = new BelpostLabelLogger();
        $startedAt = microtime(true);
        $userId    = (int) Auth::id();

        try {
            $result = (new BelpostPartialReceiptOpisService())->buildBatchDownload(
                $batch,
                Auth::user()->tenant_id
            );

            $logger->success($batch, $userId, BelpostLabelLog::KIND_OPIS, null, $batch->orders()->count(), $startedAt);

            return response($result['binary'], 200, [
                'Content-Type'        => $result['content_type'],
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            ]);
        } catch (\Throwable $e) {
            $code = $logger->failure($batch, $userId, BelpostLabelLog::KIND_OPIS, null, $e, $startedAt);

            return response()->json([
                'success' => false,
                'code'    => $code,
                'message' => $e instanceof RuntimeException
                    ? $e->getMessage()
                    : $this->unknownLabelErrorMessage($batch),
            ], 422);
        }
    }

    /**
     * POST /belpost/batches/{batch}/download-blanks
     * GET  /belpost/batches/{batch}/labels.pdf
     * Stream CRM-generated Belpost blanks. Every attempt is recorded in belpost_label_logs.
     *
     * @return \Illuminate\Http\Response|JsonResponse
     */
    public function downloadBlanks(Request $request, MailBatch $batch)
    {
        $request->validate([
            'label_size' => ['nullable', 'string', 'in:' . implode(',', MailBatch::LABEL_SIZES)],
        ]);

        $labelSize = $request->input('label_size')
            ?? $batch->label_size
            ?? TenantSetting::get('belpost_label_size', '150x100');

        $logger    = new BelpostLabelLogger();
        $startedAt = microtime(true);
        $userId    = (int) Auth::id();

        try {
            $result = (new BelpostLabelService())->generate($batch, $labelSize);

            $logger->success($batch, $userId, BelpostLabelLog::KIND_BLANKS, $labelSize, count($result['labels']), $startedAt);

            return response($result['binary'], 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            ]);
        } catch (\Throwable $e) {
            $code = $logger->failure($batch, $userId, BelpostLabelLog::KIND_BLANKS, $labelSize, $e, $startedAt);

            return response()->json([
                'success' => false,
                'code'    => $code,
                'message' => $e instanceof BelpostLabelException && $code !== BelpostLabelException::PDF_RENDER_FAILED
                    ? $e->getMessage()
                    : $this->unknownLabelErrorMessage($batch),
            ], 422);
        }
    }

    private function unknownLabelErrorMessage(MailBatch $batch): string
    {
        return 'Не удалось подготовить бланки. Сообщите в поддержку, партия ' . $batch->batch_id . '.';
    }

    /**
     * Attach weight (grams) and tariff hint to eligible order rows.
     *
     * @param  \Illuminate\Support\Collection|array<int, Order>  $orders
     * @return \Illuminate\Support\Collection
     */
    protected function withWeightHints($orders)
    {
        $names = [];
        foreach ($orders as $order) {
            foreach ($order->goods ?? [] as $name) {
                $trimmed = trim((string) $name);
                if ($trimmed !== '') {
                    $names[$trimmed] = true;
                }
            }
        }

        $productWeights = $names === []
            ? []
            : Product::query()
                ->whereIn('name', array_keys($names))
                ->pluck('weight', 'name')
                ->all();

        return collect($orders)->map(function (Order $order) use ($productWeights) {
            $goods      = $order->goods ?? [];
            $quantities = $order->quantities ?? [];
            $prices     = $order->prices ?? [];
            $weight     = (count($goods) !== count($quantities) || count($goods) !== count($prices))
                ? 0
                : BelpostService::sumGoodsWeightGrams($goods, $quantities, $productWeights);

            $row = $order->only([
                'id', 'full_name', 'city', 'street', 'building', 'housing',
                'apartment', 'phone', 'goods', 'quantities', 'prices', 'poste_restante',
            ]);
            $row['weight']      = $weight;
            $row['weight_hint'] = MailBatch::weightHint((float) $weight);

            return $row;
        })->values();
    }
}
