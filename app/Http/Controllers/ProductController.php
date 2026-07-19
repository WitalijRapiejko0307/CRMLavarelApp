<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /products
     * Products management page with stock and revenue stats.
     */
    public function index(): Response
    {
        $products = Product::orderBy('name')->get();

        // sumOrder stats: count of "Посчитан" orders per delivery type
        $counted = Order::whereIn('status', ['Посчитан'])
            ->selectRaw('delivery_type, COUNT(*) as count')
            ->groupBy('delivery_type')
            ->pluck('count', 'delivery_type')
            ->toArray();

        $srEnabled = TenantSetting::get('sr_enabled', '') === '1';

        return Inertia::render('Products/Index', [
            'products'  => $products,
            'counted'   => $counted,
            'srEnabled' => $srEnabled,
        ]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * POST /products
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'stock'      => ['required', 'integer', 'min:0'],
            'weight'     => ['required', 'numeric', 'min:0'],
            'sr_item_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = Auth::user()->tenant_id;

        if (Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $data['name'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Товар с таким названием уже существует',
            ], 422);
        }

        $product = Product::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json(['success' => true, 'product' => $product]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * PUT /products/{product}
     * Update name, weight, or add stock (приход товара).
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'weight'      => ['sometimes', 'numeric', 'min:0'],
            'stock_delta' => ['sometimes', 'integer'],
            'sr_item_id'  => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($data['name']) && $data['name'] !== $product->name) {
            $tenantId = Auth::user()->tenant_id;
            if (Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $data['name'])->where('id', '!=', $product->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар с таким названием уже существует',
                ], 422);
            }
            $product->name = $data['name'];
        }

        if (isset($data['weight'])) {
            $product->weight = $data['weight'];
        }

        if (array_key_exists('sr_item_id', $data)) {
            $product->sr_item_id = $data['sr_item_id'] ?: null;
        }

        // Stock intake (приход товара): positive delta adds, negative subtracts
        if (isset($data['stock_delta'])) {
            $product->stock = max(0, $product->stock + (int)$data['stock_delta']);
        }

        $product->save();

        return response()->json(['success' => true, 'product' => $product->fresh()]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    /**
     * DELETE /products/{product}
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['success' => true]);
    }
}
