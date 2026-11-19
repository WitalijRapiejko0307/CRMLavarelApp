<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(protected CustomerService $customers)
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    // ─── Order form helpers (JSON) ────────────────────────────────────────────

    /**
     * GET /api/customers/lookup?phone=...&order_id=...
     * Store customer summary for auto-fill and history warnings.
     * With order_id the store is taken from the order (call-center operators work
     * with the store's orders); without it, from the current user's tenant.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => ['required', 'string', 'max:30'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $excludeId = null;

        if ($request->filled('order_id')) {
            $order = Order::withoutGlobalScopes()->findOrFail((int) $request->input('order_id'));
            $this->authorize('view', $order);
            $tenantId  = (int) $order->tenant_id;
            $excludeId = $order->id;
        } else {
            abort_if(Auth::user()->tenant->isCallCenter(), 403);
            $tenantId = (int) Auth::user()->tenant_id;
        }

        return response()->json($this->customers->summary($tenantId, $request->input('phone'), $excludeId));
    }

    /**
     * POST /orders/{order}/customer-prefill/ack
     */
    public function acknowledgePrefill(Order $order): JsonResponse
    {
        $this->authorize('update', $order);
        $this->customers->acknowledgePrefill($order);

        return response()->json(null, 204);
    }

    // ─── Customers page (store only) ──────────────────────────────────────────

    public function index(Request $request): Response
    {
        $request->validate([
            'q'       => ['nullable', 'string', 'max:100'],
            'problem' => ['nullable', 'in:0,1'],
        ]);

        $search = trim((string) $request->input('q', ''));

        $query = Customer::query()->orderByDesc('last_order_at')->orderByDesc('id');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('full_name', 'like', '%' . $search . '%');
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', '%' . $digits . '%');
                }
            });
        }

        if ($request->input('problem') === '1') {
            $query->whereIn('id', Order::query()
                ->whereIn('status', CustomerService::PROBLEM_STATUSES)
                ->whereNotNull('customer_id')
                ->select('customer_id'));
        }

        $customers = $query->paginate(30)->withQueryString();

        $ids = $customers->pluck('id');

        $problemRows = Order::query()
            ->whereIn('customer_id', $ids)
            ->whereIn('status', CustomerService::PROBLEM_STATUSES)
            ->selectRaw('customer_id, status, count(*) as total')
            ->groupBy('customer_id', 'status')
            ->get()
            ->groupBy('customer_id');

        $blacklisted = Order::query()
            ->whereIn('customer_id', $ids)
            ->where('full_name', 'like', '%' . CustomerService::BLACKLIST_MARK . '%')
            ->pluck('customer_id')
            ->unique()
            ->flip();

        $customers->through(fn (Customer $c) => [
            'id'            => $c->id,
            'full_name'     => $c->full_name,
            'phone'         => $c->phone,
            'city'          => $c->city,
            'delivery_type' => $c->delivery_type,
            'orders_count'  => $c->orders_count,
            'last_order_at' => optional($c->last_order_at)->toIso8601String(),
            'problems'      => ($problemRows[$c->id] ?? collect())
                ->mapWithKeys(fn ($row) => [$row->status => (int) $row->total])
                ->all(),
            'blacklisted'   => isset($blacklisted[$c->id]),
        ]);

        return Inertia::render('Customers/Index', [
            'customers'     => $customers,
            'filters'       => ['q' => $search, 'problem' => $request->input('problem') === '1' ? '1' : '0'],
            'deliveryTypes' => Order::DELIVERY_TYPES,
        ]);
    }

    public function show(Customer $customer): Response
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'full_name', 'status', 'goods', 'quantities', 'prices', 'created_at', 'track_number'])
            ->map(fn (Order $o) => [
                'id'           => $o->id,
                'status'       => $o->status,
                'goods'        => $o->goods ?? [],
                'total'        => $o->computedOrderTotal(),
                'created_at'   => optional($o->created_at)->toIso8601String(),
                'track_number' => $o->track_number,
                'problem'      => in_array($o->status, CustomerService::PROBLEM_STATUSES, true),
                'blacklisted'  => str_contains((string) $o->full_name, CustomerService::BLACKLIST_MARK),
            ])
            ->all();

        return Inertia::render('Customers/Show', [
            'customer'      => $customer,
            'orders'        => $orders,
            'deliveryTypes' => Order::DELIVERY_TYPES,
        ]);
    }
}
