<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\TrackingRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    // ─── Manual create ────────────────────────────────────────────────────────

    /**
     * GET /orders/create
     */
    public function create(): Response
    {
        return Inertia::render('Orders/Create', [
            'statuses'       => Order::STATUSES,
            'deliveryTypes'  => Order::DELIVERY_TYPES,
            'products'       => Product::orderBy('name')->get(['id', 'name', 'stock']),
        ]);
    }

    /**
     * POST /orders
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name'  => ['required', 'string', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'status'     => ['required', 'in:' . implode(',', Order::STATUSES)],
            'goods'      => ['nullable', 'array'],
            'quantities' => ['nullable', 'array'],
            'prices'     => ['nullable', 'array'],
            'city'       => ['nullable', 'string', 'max:100'],
            'street'     => ['nullable', 'string', 'max:100'],
            'building'   => ['nullable', 'string', 'max:20'],
            'housing'    => ['nullable', 'string', 'max:20'],
            'apartment'  => ['nullable', 'string', 'max:20'],
            'source'             => ['nullable', 'string', 'max:50'],
            'sms_log'            => ['nullable', 'string', 'max:1000'],
            'delivery_type'      => ['nullable', Order::deliveryTypeRule()],
            'belpost_address_id' => ['nullable', 'string', 'max:50'],
        ]);

        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['source']  ??= 'manual';

        $order = Order::create($data);

        return redirect()->route('orders.show', $order)
            ->with('message', 'Заказ создан.');
    }

    // ─── CSV Import ───────────────────────────────────────────────────────────

    /**
     * GET /orders/import
     */
    public function importPage(): Response
    {
        return Inertia::render('Orders/Import', [
            'statuses' => Order::STATUSES,
        ]);
    }

    /**
     * POST /orders/import-csv
     *
     * Accepts a CSV file. First row must be headers.
     * Supported header names (case-insensitive):
     *   external_id, created_at, full_name, status, goods, quantities,
     *   city, street, building, housing, apartment, phone,
     *   prices, track_number, delivery_type, source
     *
     * Rows with an existing external_id (within the tenant) are skipped.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $path     = $request->file('file')->getRealPath();
        $tenantId = Auth::user()->tenant_id;

        $handle = fopen($path, 'r');
        if (!$handle) {
            return response()->json(['success' => false, 'message' => 'Не удалось открыть файл']);
        }

        // Detect delimiter from first line
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        // Read header row
        $rawHeaders = fgetcsv($handle, 0, $delimiter);
        if (!$rawHeaders) {
            fclose($handle);
            return response()->json(['success' => false, 'message' => 'Пустой файл или неверный формат']);
        }

        $headers = array_map(fn($h) => mb_strtolower(trim($h)), $rawHeaders);

        // Map column aliases to field names
        $aliasMap = [
            'id'            => 'external_id',
            'external_id'   => 'external_id',
            'created_at'    => 'created_at',
            'дата'          => 'created_at',
            'full_name'     => 'full_name',
            'фио'           => 'full_name',
            'имя'           => 'full_name',
            'status'        => 'status',
            'статус'        => 'status',
            'goods'         => 'goods',
            'товары'        => 'goods',
            'товар'         => 'goods',
            'quantities'    => 'quantities',
            'количество'    => 'quantities',
            'кол-во'        => 'quantities',
            'city'          => 'city',
            'город'         => 'city',
            'street'        => 'street',
            'улица'         => 'street',
            'building'      => 'building',
            'дом'           => 'building',
            'housing'       => 'housing',
            'корпус'        => 'housing',
            'apartment'     => 'apartment',
            'кв'            => 'apartment',
            'квартира'      => 'apartment',
            'phone'         => 'phone',
            'телефон'       => 'phone',
            'prices'        => 'prices',
            'цены'          => 'prices',
            'цена'          => 'prices',
            'track_number'  => 'track_number',
            'трек'          => 'track_number',
            'track'         => 'track_number',
            'delivery_type' => 'delivery_type',
            'доставка'      => 'delivery_type',
            'source'        => 'source',
            'источник'      => 'source',
        ];

        // Build column index → field map
        $colMap = [];
        foreach ($headers as $idx => $h) {
            if (isset($aliasMap[$h])) {
                $colMap[$idx] = $aliasMap[$h];
            }
        }

        $created = 0;
        $skipped = 0;
        $errors  = 0;
        $rowNum  = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) {
                continue; // skip blank lines
            }

            try {
                $data = ['tenant_id' => $tenantId];

                foreach ($colMap as $idx => $field) {
                    $value = isset($row[$idx]) ? trim($row[$idx]) : null;

                    if ($value === '' || $value === null) {
                        continue;
                    }

                    // JSON array fields
                    if (in_array($field, ['goods', 'quantities', 'prices'])) {
                        $data[$field] = array_map('trim', explode(',', $value));
                    } elseif ($field === 'delivery_type') {
                        $map = [
                            'белпочта' => 'belpost', 'belpost' => 'belpost',
                            'европочта' => 'europochta', 'europochta' => 'europochta',
                            'курьер' => 'courier', 'courier' => 'courier',
                            'самовывоз' => 'pickup', 'pickup' => 'pickup',
                            'лично' => 'personal', 'personal' => 'personal',
                        ];
                        $data[$field] = $map[mb_strtolower($value)] ?? null;
                    } elseif ($field === 'status') {
                        $data[$field] = in_array($value, Order::STATUSES) ? $value : 'Позвонить';
                    } else {
                        $data[$field] = $value;
                    }
                }

                // Skip if external_id already exists for this tenant
                if (!empty($data['external_id'])) {
                    $exists = Order::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('external_id', $data['external_id'])
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                }

                if (empty($data['full_name'])) {
                    $skipped++;
                    continue;
                }

                if (empty($data['status'])) {
                    $data['status'] = 'Позвонить';
                }

                Order::create($data);
                $created++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        fclose($handle);

        return response()->json([
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    public function index(Request $request): Response
    {
        $query = Order::query()->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $orders = $query->paginate(50)->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders'        => $orders,
            'filters'       => $request->only('search', 'status', 'date_from', 'date_to'),
            'statuses'      => Order::STATUSES,
            'deliveryTypes' => Order::DELIVERY_TYPES,
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load('statusHistory');

        return Inertia::render('Orders/Show', [
            'order'         => $order,
            'statuses'      => Order::STATUSES,
            'deliveryTypes' => Order::DELIVERY_TYPES,
            'products'      => Product::orderBy('name')->get(['id', 'name', 'stock']),
        ]);
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate([
            'full_name'     => ['sometimes', 'required', 'string', 'max:255'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:20'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'street'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'building'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'housing'       => ['sometimes', 'nullable', 'string', 'max:20'],
            'apartment'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'goods'         => ['sometimes', 'nullable', 'array'],
            'quantities'    => ['sometimes', 'nullable', 'array'],
            'prices'        => ['sometimes', 'nullable', 'array'],
            'track_number'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'source'             => ['sometimes', 'nullable', 'string', 'max:50'],
            'belpost_address_id' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $order->update($data);

        return back()->with('message', 'Заказ обновлён.');
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => ['required', 'in:' . implode(',', Order::STATUSES)],
        ]);

        $order->update(['status' => $request->input('status')]);

        return back()->with('message', 'Статус обновлён.');
    }

    public function updateDeliveryType(Request $request, Order $order)
    {
        $request->validate([
            'delivery_type' => ['required', Order::deliveryTypeRule()],
        ]);

        $order->update(['delivery_type' => $request->input('delivery_type')]);

        return back()->with('message', 'Тип доставки обновлён.');
    }

    public function destroy(Order $order)
    {
        $order->delete();
        return redirect()->route('orders.index')->with('message', 'Заказ удалён.');
    }

    // ─── Tracking status refresh ──────────────────────────────────────────────

    /**
     * POST /orders/refresh-tracking
     */
    public function refreshTracking(TrackingRunService $service): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $result   = $service->startRun($tenantId, 'manual');

        if (!$result['ok']) {
            return response()->json([
                'message'  => 'Проверка статусов уже выполняется',
                'progress' => $result['progress'],
            ], 409);
        }

        return response()->json([
            'total'  => $result['total'],
            'status' => $result['status'],
        ], 202);
    }

    /**
     * GET /api/orders/tracking-status
     */
    public function trackingStatus(TrackingRunService $service): JsonResponse
    {
        $progress = $service->getProgress(Auth::user()->tenant_id);

        if (!$progress) {
            return response()->json([
                'status'      => 'idle',
                'checked'     => 0,
                'total'       => 0,
                'errors'      => 0,
                'source'      => null,
                'finished_at' => null,
            ]);
        }

        return response()->json([
            'status'      => $progress['status'] ?? 'idle',
            'checked'     => $progress['checked'] ?? 0,
            'total'       => $progress['total'] ?? 0,
            'errors'      => $progress['errors'] ?? 0,
            'source'      => $progress['source'] ?? null,
            'finished_at' => $progress['finished_at'] ?? null,
        ]);
    }

    /**
     * POST /api/tracking/auto-notice/dismiss
     */
    public function dismissTrackingNotice(): JsonResponse
    {
        $user   = Auth::user();
        $lastAt = TenantSetting::get('tracking_last_auto_at');

        if ($lastAt) {
            $user->update(['tracking_auto_seen_at' => $lastAt]);
        }

        return response()->json(null, 204);
    }
}
