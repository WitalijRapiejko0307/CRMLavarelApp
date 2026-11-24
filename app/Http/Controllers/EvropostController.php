<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Services\EvropostLabelDelivery;
use App\Services\EvropostLabelLogger;
use App\Services\EvropostLabelProbe;
use App\Services\EvropostService;
use App\Support\TenantDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class EvropostController extends Controller
{
    private const REGISTERED_DAYS  = 30;
    private const REGISTERED_LIMIT = 200;
    private const LABELS_MAX       = 50;

    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /europochta
     * Europochta shipment registration page.
     * Shows all orders with status "Отправить" and delivery "europochta" without a track number.
     */
    public function index(): Response
    {
        $eligibleOrders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'europochta')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->get(['id', 'full_name', 'city', 'street', 'building', 'housing', 'apartment', 'phone', 'goods', 'quantities', 'prices', 'variations', 'ops_id', 'europochta_store_id', 'europochta_store_start_id', 'europochta_store_start_label']);

        $tenantId = (int) Auth::user()->tenant_id;

        $registeredOrders = Order::query()
            ->where('status', 'Оформлен')
            ->where('delivery_type', 'europochta')
            ->whereNotNull('track_number')
            ->where('track_number', '!=', '')
            ->where('status_changed_at', '>=', now()->subDays(self::REGISTERED_DAYS))
            ->orderBy('status_changed_at', 'desc')
            ->limit(self::REGISTERED_LIMIT)
            ->get(['id', 'full_name', 'phone', 'city', 'street', 'building', 'ops_id', 'track_number', 'status_changed_at']);

        return Inertia::render('Europochta/Create', [
            'eligibleOrders'   => $eligibleOrders,
            'registeredOrders' => $registeredOrders,
            'labelFormat'      => $this->labelFormat(),
            'epApiVersion'     => $this->epApiVersion(),
            'senderOps'        => TenantDefaults::senderOps($tenantId),
        ]);
    }

    // ─── Process single order ─────────────────────────────────────────────────

    /**
     * POST /europochta/orders/{order}/register
     * Register a single order on Europochta.
     *
     * Body: { who_pays: 'Покупатель'|'Продавец' }
     * API version is read from tenant settings (ep_api_version).
     */
    public function register(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'who_pays' => ['required', 'string', 'in:Покупатель,Продавец'],
        ]);

        $whoPays = $request->input('who_pays');
        $useNew  = TenantSetting::get('ep_api_version', 'new') !== 'legacy';

        try {
            /** @var EvropostService $service */
            $service = app(EvropostService::class);

            $result = $useNew
                ? $service->createItemNew($order, Auth::user()->tenant_id, $whoPays)
                : $service->createItem($order, Auth::user()->tenant_id, $whoPays);

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('EvropostController::register error', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success'       => false,
                'error'         => 'exception',
                'error_message' => $e->getMessage(),
            ], 422);
        }
    }

    // ─── Process all eligible orders ─────────────────────────────────────────

    /**
     * POST /europochta/register-all
     * Register all eligible orders sequentially.
     *
     * Body: { who_pays: 'Покупатель'|'Продавец' }
     * Returns a summary array of results.
     * API version is read from tenant settings (ep_api_version).
     */
    public function registerAll(Request $request): JsonResponse
    {
        $request->validate([
            'who_pays' => ['required', 'string', 'in:Покупатель,Продавец'],
        ]);

        $whoPays  = $request->input('who_pays');
        $useNew   = TenantSetting::get('ep_api_version', 'new') !== 'legacy';
        $tenantId = Auth::user()->tenant_id;

        $orders = Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'europochta')
            ->whereNull('track_number')
            ->orderBy('created_at')
            ->get();

        /** @var EvropostService $service */
        $service = app(EvropostService::class);

        $results = [];

        foreach ($orders as $order) {
            try {
                $result = $useNew
                    ? $service->createItemNew($order, $tenantId, $whoPays)
                    : $service->createItem($order, $tenantId, $whoPays);
            } catch (\Throwable $e) {
                $result = [
                    'success'       => false,
                    'error'         => 'exception',
                    'error_message' => $e->getMessage(),
                ];
            }

            $results[$order->id] = $result;
        }

        return response()->json(['results' => $results]);
    }

    // ─── Labels ──────────────────────────────────────────────────────────────

    /**
     * POST /europochta/labels
     * Download label PDFs for selected "Оформлен" Europochta orders.
     *
     * Body: { order_ids: int[], format?: 'a5'|'thermal' }
     * Delivers whatever was downloaded: merged PDF, ZIP of PDFs (merge failed) or a single PDF.
     */
    public function labels(Request $request)
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:' . self::LABELS_MAX],
            'order_ids.*' => ['integer'],
            'format'      => ['nullable', 'string', 'in:' . EvropostService::LABEL_FORMAT_A5 . ',' . EvropostService::LABEL_FORMAT_THERMAL],
        ]);

        $tenantId = (int) Auth::user()->tenant_id;
        $logger   = new EvropostLabelLogger($tenantId);

        if ($this->epApiVersion() === 'legacy') {
            return $this->labelsError('Ярлыки доступны только в API v1.8.2');
        }

        $token = (string) TenantSetting::get('token_ep', '');
        if ($token === '') {
            return $this->labelsError('Подключите Европочту: не задан токен API');
        }

        $format = $data['format'] ?? $this->labelFormat();
        if (!empty($data['format'])) {
            TenantSetting::put($tenantId, 'ep_label_format', $format);
        }

        $ids    = array_values(array_unique(array_map('intval', $data['order_ids'])));
        $orders = Order::query()->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');

        $tracks  = [];
        $skipped = [];
        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (!$order) {
                $skipped[] = ['order_id' => $id, 'reason' => 'заявка не найдена'];
            } elseif ($order->delivery_type !== 'europochta') {
                $skipped[] = ['order_id' => $id, 'reason' => 'доставка не Европочта'];
            } elseif ($order->status !== 'Оформлен') {
                $skipped[] = ['order_id' => $id, 'reason' => 'статус не «Оформлен»'];
            } elseif (trim((string) $order->track_number) === '') {
                $skipped[] = ['order_id' => $id, 'reason' => 'нет трек-номера'];
            } else {
                $tracks[$id] = (string) $order->track_number;
            }
        }
        ksort($tracks);

        $logger->log('request', [
            'format'   => $format,
            'selected' => count($ids),
            'accepted' => count($tracks),
            'skipped'  => $skipped,
        ]);

        if (!$tracks) {
            return $this->labelsError('Нет заявок для печати: ярлыки доступны только для статуса «Оформлен»', $skipped);
        }

        set_time_limit(120);

        $service = app(EvropostService::class);
        $result  = $service->fetchLabels($tracks, $format, $token);

        foreach ($result['fetched'] as $orderId => $meta) {
            $order = $orders->get($orderId);
            $logger->log('fetch', array_merge($meta, [
                'order_id'             => $orderId,
                'seconds_since_created' => $order && $order->status_changed_at
                    ? $order->status_changed_at->diffInSeconds(now())
                    : null,
                'pdf_version'          => isset($result['pdfs'][$orderId])
                    ? EvropostLabelLogger::pdfVersion($result['pdfs'][$orderId])
                    : null,
            ]), empty($meta['ok']) ? 'warning' : 'info');
        }

        $errors = array_values($result['errors']);

        if (!$result['pdfs']) {
            return $this->labelsError('Не удалось получить ярлыки от Европочты', $skipped, $errors);
        }

        $delivery = app(EvropostLabelDelivery::class)->build($result['pdfs'], $tracks, $logger);

        if (isset($delivery['error'])) {
            return $this->labelsError($delivery['error'], $skipped, $errors);
        }

        $logger->log('delivery', [
            'kind'   => $delivery['kind'],
            'size'   => strlen($delivery['body']),
            'errors' => count($errors),
            'ok'     => true,
        ]);

        $headers = [
            'Content-Type'        => $delivery['content_type'],
            'Content-Disposition' => 'attachment; filename="' . $delivery['filename'] . '"',
            'X-Label-Kind'        => $delivery['kind'],
        ];
        if ($delivery['merge_failed']) {
            $headers['X-Label-Merge'] = 'failed';
        }
        if ($errors) {
            $headers['X-Label-Errors'] = rawurlencode((string) json_encode(array_slice($errors, 0, 20), JSON_UNESCAPED_UNICODE));
        }
        if ($skipped) {
            $headers['X-Label-Skipped'] = rawurlencode((string) json_encode(array_slice($skipped, 0, 20), JSON_UNESCAPED_UNICODE));
        }

        // Silent diagnostics of the batch endpoint, after the response is sent.
        app()->terminating(function () use ($token, $tracks, $format, $logger, $tenantId) {
            app(EvropostLabelProbe::class)->run($token, $tracks, $format, $logger, $tenantId);
        });

        return response($delivery['body'], 200, $headers);
    }

    private function labelsError(string $message, array $skipped = [], array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], 422);
    }

    private function labelFormat(): string
    {
        $value = (string) TenantSetting::get('ep_label_format', EvropostService::LABEL_FORMAT_A5);

        return in_array($value, [EvropostService::LABEL_FORMAT_A5, EvropostService::LABEL_FORMAT_THERMAL], true)
            ? $value
            : EvropostService::LABEL_FORMAT_A5;
    }

    private function epApiVersion(): string
    {
        $value = trim((string) TenantSetting::get('ep_api_version', 'new'));

        return $value !== '' ? $value : 'new';
    }
}
