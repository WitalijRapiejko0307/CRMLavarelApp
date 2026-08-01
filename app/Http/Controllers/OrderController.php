<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Rules\FullNameThreeParts;
use App\Services\OrderAssignmentService;
use App\Services\TrackingRunService;
use App\Support\CallCenterOrderQuery;
use App\Support\CsvOrderLineParser;
use App\Support\CsvOrderReader;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected OrderAssignmentService $orderAssignment
    ) {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    protected function tenant(): Tenant
    {
        return Auth::user()->tenant;
    }

    protected function isCallCenter(): bool
    {
        return $this->tenant()->isCallCenter();
    }

    // ─── Manual create ────────────────────────────────────────────────────────

    /**
     * GET /orders/create
     */
    public function create(): Response
    {
        abort_if($this->isCallCenter(), 403);

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
        abort_if($this->isCallCenter(), 403);

        $data = $request->validate([
            'full_name'  => ['required', 'string', 'max:255', new FullNameThreeParts],
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

        if (!empty($data['phone'])) {
            $data['phone'] = PhoneNormalizer::normalize($data['phone']);
        }

        $order = Order::create($data);
        $this->orderAssignment->assignCallCenter($order);

        return redirect()->route('orders.show', $order)
            ->with('message', 'Заказ создан.');
    }

    // ─── CSV Import ───────────────────────────────────────────────────────────

    /**
     * GET /orders/import
     */
    public function importPage(): Response
    {
        abort_if($this->isCallCenter(), 403);

        return Inertia::render('Orders/Import', [
            'statuses' => Order::STATUSES,
        ]);
    }

    /**
     * POST /orders/import-csv
     *
     * Accepts a CSV export from Google Sheets (лист «Заказы»).
     * Header row is detected automatically (must contain ФИО and Товар).
     * Rows with an existing external_id (within the tenant) are skipped.
     */
    public function importCsv(Request $request): JsonResponse
    {
        abort_if($this->isCallCenter(), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $path     = $request->file('file')->getRealPath();
        $tenantId = Auth::user()->tenant_id;

        try {
            $rows = CsvOrderReader::read($path);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        $created  = 0;
        $skipped  = 0;
        $errors   = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $rowNum = $row['rowNum'];
            $fields = $row['fields'];

            try {
                if (empty($fields['full_name'])) {
                    $skipped++;
                    continue;
                }

                $data = ['tenant_id' => $tenantId];

                foreach ($fields as $field => $value) {
                    if (in_array($field, ['goods', 'quantities', 'prices'], true)) {
                        continue;
                    }

                    if ($field === 'phone') {
                        $data[$field] = PhoneNormalizer::normalize($value);
                        continue;
                    }

                    if ($field === 'created_at') {
                        $parsed = $this->parseImportCreatedAt($value);
                        if ($parsed !== null) {
                            $data['created_at'] = $parsed;
                        }
                        continue;
                    }

                    if ($field === 'status') {
                        if (in_array($value, Order::STATUSES, true)) {
                            $data[$field] = $value;
                        } else {
                            $data[$field] = 'Позвонить';
                            $warnings[] = [
                                'row'     => $rowNum,
                                'message' => "Неизвестный статус «{$value}», установлен «Позвонить»",
                            ];
                        }
                        continue;
                    }

                    if ($field === 'delivery_type') {
                        $data[$field] = CsvOrderReader::mapDeliveryType($value);
                        continue;
                    }

                    $data[$field] = $value;
                }

                try {
                    $lineItems = CsvOrderLineParser::parse(
                        $fields['goods'] ?? '',
                        $fields['quantities'] ?? null,
                        $fields['prices'] ?? null,
                    );
                    $data['goods']      = $lineItems['goods'];
                    $data['quantities'] = $lineItems['quantities'];
                    $data['prices']     = $lineItems['prices'];
                } catch (\InvalidArgumentException $e) {
                    $errors++;
                    $warnings[] = [
                        'row'     => $rowNum,
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }

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

                $data['status'] ??= 'Позвонить';

                $order = Order::create($data);
                $this->orderAssignment->assignCallCenter($order);
                $created++;
            } catch (\Exception $e) {
                $errors++;
                $warnings[] = [
                    'row'     => $rowNum,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success'  => true,
            'created'  => $created,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'warnings' => $warnings,
        ]);
    }

    private function parseImportCreatedAt(string $value): ?Carbon
    {
        foreach (['d.m.Y H:i', 'd.m.Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();

        if ($tenant->isCallCenter()) {
            $query = CallCenterOrderQuery::forTenant($tenant->id)
                ->with('tenant:id,name')
                ->orderByDesc('created_at');
        } else {
            $query = Order::query()->orderByDesc('created_at');
        }

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

        if ($tenant->isCallCenter() && ($storeId = $request->input('store_id'))) {
            $query->where('tenant_id', $storeId);
        }

        $orders = $query
            ->with('mailBatch:id,batch_id')
            ->paginate(50)
            ->withQueryString();

        $connectedStores = $tenant->isCallCenter()
            ? TenantConnection::where('call_center_tenant_id', $tenant->id)
                ->where('status', TenantConnection::STATUS_ACTIVE)
                ->with('store:id,name')
                ->get()
                ->map(fn ($c) => ['id' => $c->store->id, 'name' => $c->store->name])
            : [];

        return Inertia::render('Orders/Index', [
            'orders'          => $orders,
            'filters'         => $request->only('search', 'status', 'date_from', 'date_to', 'store_id'),
            'statuses'        => $tenant->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES,
            'deliveryTypes'   => Order::DELIVERY_TYPES,
            'isCallCenter'    => $tenant->isCallCenter(),
            'connectedStores' => $connectedStores,
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorize('view', $order);

        $order->load(['statusHistory', 'tenant:id,name', 'lastUpdatedBy:id,name,tenant_id']);

        $catalogQuery = Product::query();
        if ($this->isCallCenter()) {
            $catalogQuery = Product::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id);
        }

        $catalogNames = $catalogQuery->pluck('name')->all();

        return Inertia::render('Orders/Show', [
            'order'               => $order,
            'statuses'            => $this->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES,
            'deliveryTypes'       => Order::DELIVERY_TYPES,
            'products'              => $catalogQuery->orderBy('name')->get(['id', 'name', 'stock']),
            'unknownGoods'          => array_values(array_diff($order->goods ?? [], $catalogNames)),
            'isCallCenter'          => $this->isCallCenter(),
            'updatedByCallCenter'   => !$this->isCallCenter()
                && $order->lastUpdatedBy
                && $order->lastUpdatedBy->tenant_id !== $order->tenant_id,
        ]);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorize('update', $order);

        $rules = [
            'full_name'     => ['sometimes', 'required', 'string', 'max:255', new FullNameThreeParts],
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
            'delivery_type'      => ['sometimes', 'nullable', Order::deliveryTypeRule()],
            'sms_log'            => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];

        if ($this->isCallCenter()) {
            $rules = array_intersect_key($rules, array_flip([
                'full_name', 'phone', 'city', 'street', 'building', 'housing', 'apartment',
                'goods', 'quantities', 'prices', 'source', 'delivery_type', 'sms_log',
            ]));
        }

        $data = $request->validate($rules);

        if ($this->isCallCenter()) {
            $data = array_intersect_key($data, array_flip(Order::CALL_CENTER_EDITABLE_FIELDS));
        }

        if (array_key_exists('phone', $data) && !empty($data['phone'])) {
            $data['phone'] = PhoneNormalizer::normalize($data['phone']);
        }

        $data['last_updated_by_user_id'] = Auth::id();

        $order->update($data);

        return back()->with('message', 'Заказ обновлён.');
    }

    public function updateStatus(Request $request, Order $order)
    {
        $this->authorize('updateStatus', $order);

        $allowed = $this->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES;

        $request->validate([
            'status' => ['required', 'in:' . implode(',', $allowed)],
        ]);

        $order->update([
            'status'                  => $request->input('status'),
            'last_updated_by_user_id' => Auth::id(),
        ]);

        return back()->with('message', 'Статус обновлён.');
    }

    public function updateDeliveryType(Request $request, Order $order)
    {
        $this->authorize('updateDeliveryType', $order);

        $request->validate([
            'delivery_type' => ['required', Order::deliveryTypeRule()],
        ]);

        $order->update([
            'delivery_type'           => $request->input('delivery_type'),
            'last_updated_by_user_id' => Auth::id(),
        ]);

        return back()->with('message', 'Тип доставки обновлён.');
    }

    public function destroy(Request $request, Order $order)
    {
        $this->authorize('delete', $order);
        abort_unless(
            Order::isDeletable($order),
            422,
            'Заказ в текущем статусе удалить нельзя.'
        );

        $order->delete();

        if ($request->wantsJson()) {
            return response()->json(null, 204);
        }

        return redirect()->route('orders.index')->with('message', 'Заказ удалён.');
    }

    // ─── Tracking status refresh ──────────────────────────────────────────────

    /**
     * POST /orders/refresh-tracking
     */
    public function refreshTracking(TrackingRunService $service): JsonResponse
    {
        abort_if($this->isCallCenter(), 403);

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
     * POST /orders/cancel-tracking
     */
    public function cancelTracking(TrackingRunService $service): JsonResponse
    {
        abort_if($this->isCallCenter(), 403);

        $tenantId = Auth::user()->tenant_id;

        if (!$service->requestCancel($tenantId)) {
            return response()->json([
                'message' => 'Остановить можно только ручную проверку статусов',
            ], 409);
        }

        return response()->json(null, 204);
    }

    /**
     * GET /api/orders/tracking-status
     */
    public function trackingStatus(TrackingRunService $service): JsonResponse
    {
        if ($this->isCallCenter()) {
            return response()->json([
                'status'      => 'idle',
                'checked'     => 0,
                'total'       => 0,
                'errors'      => 0,
                'source'      => null,
                'finished_at' => null,
            ]);
        }

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
