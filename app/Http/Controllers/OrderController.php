<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use App\Rules\BelarusPhone;
use App\Rules\FullNameTwoParts;
use App\Rules\HasAtLeastOneGood;
use App\Services\OrderAssignmentService;
use App\Services\OrderDuplicateService;
use App\Services\OrderHandlerService;
use App\Services\TrackingRunService;
use App\Support\CallCenterOrderQuery;
use App\Support\CsvOrderLineParser;
use App\Support\OrderIndexSort;
use App\Support\OrderSegment;
use App\Support\CsvOrderReader;
use App\Support\PhoneNormalizer;
use App\Support\ProductLinkResolver;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected OrderAssignmentService $orderAssignment,
        protected OrderHandlerService $orderHandlers,
        protected OrderDuplicateService $orderDuplicates,
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
            'full_name'  => ['required', 'string', 'max:255', new FullNameTwoParts],
            'phone'      => ['required', 'string', 'max:20', new BelarusPhone],
            'status'     => ['required', 'in:' . implode(',', Order::STATUSES)],
            'goods'      => ['required', 'array', new HasAtLeastOneGood],
            'quantities' => ['nullable', 'array'],
            'prices'     => ['nullable', 'array'],
            'city'       => ['nullable', 'string', 'max:100'],
            'street'     => ['nullable', 'string', 'max:100'],
            'building'   => ['nullable', 'string', 'max:20'],
            'housing'    => ['nullable', 'string', 'max:20'],
            'apartment'  => ['nullable', 'string', 'max:20'],
            'source'             => ['nullable', 'string', 'max:50'],
            'comment'            => ['nullable', 'string', 'max:2000'],
            'upsell'             => ['nullable', 'string', 'max:1000'],
            'cross_sell'         => ['nullable', 'string', 'max:1000'],
            'delivery_type'      => ['nullable', Order::deliveryTypeRule()],
            'belpost_address_id' => ['nullable', 'string', 'max:50'],
            'poste_restante'     => ['sometimes', 'boolean'],
        ]);

        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['source']  ??= 'manual';
        $data['phone'] = PhoneNormalizer::normalize($data['phone']);
        $data['poste_restante'] = $request->boolean('poste_restante');
        $data = Order::applyPosteRestanteDefaults($data);

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
                        } else {
                            $warnings[] = [
                                'row'     => $rowNum,
                                'message' => "Не удалось распознать дату «{$value}», использована текущая дата",
                            ];
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

        $user = Auth::user();

        if ($tenant->isCallCenter()) {
            $query = CallCenterOrderQuery::forTenant($tenant->id)
                ->with('tenant:id,name');

            CallCenterOrderQuery::applyAssigneeVisibility($query, $user);

            if (
                CallCenterOrderQuery::roundRobinEnabled($tenant->id)
                && in_array($user->role, ['admin', 'manager'], true)
                && $request->input('assignee') === 'mine'
            ) {
                CallCenterOrderQuery::applyMineFilter($query, $user);
            }
        } else {
            $query = Order::query();
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('external_id', 'like', "%{$search}%")
                  ->orWhere('track_number', 'like', "%{$search}%")
                  ->orWhereRaw('CAST(goods AS CHAR) LIKE ?', ['%'.$search.'%']);
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

        $segment = $request->input('segment');
        if (is_string($segment) && $segment !== '') {
            OrderSegment::apply($query, $segment);
        }

        $deliveryType = $request->input('delivery_type');
        if (is_string($deliveryType) && array_key_exists($deliveryType, Order::DELIVERY_TYPES)) {
            $query->where('delivery_type', $deliveryType);
        }

        [$sort, $dir] = OrderIndexSort::resolve($request->input('sort'), $request->input('dir'));
        OrderIndexSort::apply($query, $sort, $dir);

        $orders = $query
            ->with('mailBatch:id,batch_id')
            ->paginate(50)
            ->withQueryString();

        $orderHandlers = $tenant->isCallCenter()
            ? $this->orderHandlers->handlersForOrders(collect($orders->items())->pluck('id'))
            : [];

        $this->orderDuplicates->attachDuplicateFlags(collect($orders->items()));

        $connectedStores = $tenant->isCallCenter()
            ? TenantConnection::where('call_center_tenant_id', $tenant->id)
                ->where('status', TenantConnection::STATUS_ACTIVE)
                ->with('store:id,name')
                ->get()
                ->map(fn ($c) => ['id' => $c->store->id, 'name' => $c->store->name])
            : [];

        return Inertia::render('Orders/Index', [
            'orders'          => $orders,
            'filters'         => array_merge(
                $request->only('search', 'status', 'date_from', 'date_to', 'store_id', 'segment', 'delivery_type', 'assignee'),
                ['sort' => $sort, 'dir' => $dir],
            ),
            'statuses'             => $tenant->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES,
            'bulkConfirmStatuses'  => Order::BULK_CONFIRM_STATUSES,
            'deliveryTypes'        => Order::DELIVERY_TYPES,
            'segments'             => OrderSegment::labels(),
            'isCallCenter'    => $tenant->isCallCenter(),
            'connectedStores' => $connectedStores,
            'orderHandlers'   => $orderHandlers,
            'roundRobinEnabled' => $tenant->isCallCenter()
                && CallCenterOrderQuery::roundRobinEnabled($tenant->id),
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorize('view', $order);

        $order->load(['statusHistory.user:id,name', 'tenant:id,name', 'lastUpdatedBy:id,name,tenant_id']);

        $catalogQuery = Product::query();
        if ($this->isCallCenter()) {
            $catalogQuery = Product::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id);
        }

        $catalogNames = $catalogQuery->pluck('name')->all();

        $duplicateFlags = $this->orderDuplicates->flagsForOrder($order);
        $order->setAttribute('is_phone_duplicate', $duplicateFlags['is_phone_duplicate']);
        $order->setAttribute('duplicate_of_order_id', $duplicateFlags['duplicate_of_order_id']);

        $productColumns = ['id', 'name', 'stock', 'upsell_name', 'upsell_price', 'upsell_text', 'cross_name', 'cross_price', 'cross_text', 'manager_note'];

        return Inertia::render('Orders/Show', [
            'order'               => $order,
            'statuses'            => $this->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES,
            'deliveryTypes'       => Order::DELIVERY_TYPES,
            'products'              => $catalogQuery->orderBy('name')->get($productColumns),
            'unknownGoods'          => array_values(array_diff($order->goods ?? [], $catalogNames)),
            'isCallCenter'          => $this->isCallCenter(),
            'updatedByCallCenter'   => !$this->isCallCenter()
                && $order->lastUpdatedBy
                && $order->lastUpdatedBy->tenant_id !== $order->tenant_id,
            'orderHandlers'         => $this->orderHandlers->handlersForOrder($order->id),
            'productLinks'          => ProductLinkResolver::forOrder($order),
            'phoneHistory'          => $this->phoneReturnHistory($order),
            'callScript'            => $this->isCallCenter() ? $this->renderCallScript($order) : null,
        ]);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorize('update', $order);

        $rules = [
            'full_name'     => ['sometimes', 'required', 'string', 'max:255', new FullNameTwoParts],
            'phone'         => ['sometimes', 'required', 'string', 'max:20', new BelarusPhone],
            'city'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'street'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'building'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'housing'       => ['sometimes', 'nullable', 'string', 'max:20'],
            'apartment'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'goods'         => ['sometimes', 'required', 'array', new HasAtLeastOneGood],
            'quantities'    => ['sometimes', 'nullable', 'array'],
            'prices'        => ['sometimes', 'nullable', 'array'],
            'track_number'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'source'             => ['sometimes', 'nullable', 'string', 'max:50'],
            'belpost_address_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'delivery_type'      => ['sometimes', 'nullable', Order::deliveryTypeRule()],
            'comment'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'upsell'             => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cross_sell'         => ['sometimes', 'nullable', 'string', 'max:1000'],
            'poste_restante'     => ['sometimes', 'boolean'],
            'callback_at'        => ['sometimes', 'nullable', 'date'],
        ];

        if ($this->isCallCenter()) {
            $rules = array_intersect_key($rules, array_flip([
                'full_name', 'phone', 'city', 'street', 'building', 'housing', 'apartment',
                'goods', 'quantities', 'prices', 'source', 'delivery_type',
                'comment', 'upsell', 'cross_sell', 'poste_restante', 'callback_at',
            ]));
        }

        $data = $request->validate($rules);

        if ($this->isCallCenter()) {
            $data = array_intersect_key($data, array_flip(Order::CALL_CENTER_EDITABLE_FIELDS));
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = PhoneNormalizer::normalize($data['phone']);
        }

        if (array_key_exists('poste_restante', $data)) {
            $data['poste_restante'] = $request->boolean('poste_restante');
            $data = Order::applyPosteRestanteDefaults($data);
        }

        $data['last_updated_by_user_id'] = Auth::id();

        $order->update($data);

        return back()->with('message', 'Заказ обновлён.');
    }

    public function updateStatus(Request $request, Order $order)
    {
        $this->authorize('updateStatus', $order);

        $allowed = $this->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES;

        $rules = [
            'status' => ['required', 'in:' . implode(',', $allowed)],
        ];

        if ($request->input('status') === 'Дубль') {
            $rules['funnel_exclude'] = ['sometimes', 'boolean'];
            $rules['funnel_reason']  = ['sometimes', 'nullable', 'in:' . implode(',', array_keys(Order::FUNNEL_EXCLUDE_REASONS))];
        }

        if ($request->input('status') === 'Перезвонить') {
            $rules['callback_at'] = ['required', 'date'];
        }

        $request->validate($rules);

        $status = $request->input('status');

        $payload = array_merge([
            'status'                  => $status,
            'last_updated_by_user_id' => Auth::id(),
        ], $this->funnelFieldsForStatusChange($order, $status, $request));

        if ($status === 'Перезвонить') {
            $payload['callback_at'] = $request->input('callback_at');
        }

        $order->update($payload);

        return back()->with('message', 'Статус обновлён.');
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $allowed = $this->isCallCenter() ? Order::CALL_CENTER_STATUSES : Order::STATUSES;

        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct'],
            'status'      => ['required', 'in:' . implode(',', $allowed)],
        ]);

        $updated = 0;
        $failed  = [];

        foreach ($data['order_ids'] as $id) {
            $order = Order::find($id);

            if (!$order) {
                $failed[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            try {
                $this->authorize('updateStatus', $order);
            } catch (AuthorizationException) {
                $failed[] = ['id' => $id, 'reason' => 'forbidden'];
                continue;
            }

            $order->update(array_merge([
                'status'                  => $data['status'],
                'last_updated_by_user_id' => Auth::id(),
            ], $this->funnelFieldsForStatusChange($order, $data['status'])));
            $updated++;
        }

        return response()->json([
            'updated' => $updated,
            'failed'  => $failed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function funnelFieldsForStatusChange(Order $order, string $newStatus, ?Request $request = null): array
    {
        if ($newStatus === 'Дубль') {
            $fields = [
                'funnel_exclude' => $request && $request->exists('funnel_exclude')
                    ? $request->boolean('funnel_exclude')
                    : true,
            ];

            $reason = $request?->input('funnel_reason');
            if ($reason && $reason !== 'duplicate' && isset(Order::FUNNEL_EXCLUDE_REASONS[$reason])) {
                $label   = Order::FUNNEL_EXCLUDE_REASONS[$reason];
                $comment = (string) ($order->comment ?? '');
                if ($comment === '') {
                    $fields['comment'] = $label;
                } elseif (!str_contains($comment, $label)) {
                    $fields['comment'] = $comment . "\n" . $label;
                }
            }

            return $fields;
        }

        if ($order->status === 'Дубль') {
            return ['funnel_exclude' => false];
        }

        return [];
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

    /**
     * POST /api/sr-sync/failures/dismiss
     */
    public function dismissSrSyncFailures(): JsonResponse
    {
        $user   = Auth::user();
        $lastAt = TenantSetting::get('sr_last_sync_at');

        if ($lastAt) {
            TenantSetting::put($user->tenant_id, 'sr_sync_failures_seen_at', $lastAt);
        }

        return response()->json(null, 204);
    }

    private function renderCallScript(Order $order): string
    {
        $template = (string) TenantSetting::get('call_script', '');
        if ($template === '') {
            return '';
        }

        $goods = $order->goods ?? [];
        $tovar = $goods[0] ?? '';
        $sum   = 0.0;

        foreach ($order->prices ?? [] as $i => $price) {
            $qty = $order->quantities[$i] ?? 1;
            $sum += (float) $price * (float) $qty;
        }

        return strtr($template, [
            '{name}'  => (string) $order->full_name,
            '{tovar}' => (string) $tovar,
            '{sum}'   => number_format($sum, 2, '.', ''),
        ]);
    }

    /**
     * @return list<array{id:int, full_name:string, status:string, created_at:?string, track_number:?string}>
     */
    private function phoneReturnHistory(Order $order): array
    {
        $suffix = PhoneNormalizer::lastNineDigits($order->phone);
        if ($suffix === '') {
            return [];
        }

        return Order::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('id', '!=', $order->id)
            ->whereIn('status', ['Возврат', 'Возврат в пути'])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'full_name', 'status', 'phone', 'created_at', 'track_number'])
            ->filter(fn (Order $row) => PhoneNormalizer::lastNineDigits($row->phone) === $suffix)
            ->take(10)
            ->values()
            ->map(fn (Order $row) => [
                'id'           => $row->id,
                'full_name'    => $row->full_name,
                'status'       => $row->status,
                'created_at'   => optional($row->created_at)->toIso8601String(),
                'track_number' => $row->track_number,
            ])
            ->all();
    }
}
