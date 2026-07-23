<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TenantSetting;
use App\Services\EvropostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class EvropostController extends Controller
{
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
            ->get(['id', 'full_name', 'city', 'street', 'building', 'housing', 'apartment', 'phone', 'goods', 'quantities', 'prices', 'ops_id']);

        return Inertia::render('Europochta/Create', [
            'eligibleOrders' => $eligibleOrders,
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
}
