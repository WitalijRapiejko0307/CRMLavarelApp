<?php

namespace App\Http\Controllers;

use App\Models\OrderStockMovement;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\ProductVariationService;
use App\Models\TenantSetting;
use App\Support\UrlNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable', 'tenant.type:store']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /products
     * Products management page with stock and revenue stats.
     */
    public function index(): Response
    {
        $products = Product::with('variations')->orderBy('name')->get();

        $srEnabled = TenantSetting::get('sr_enabled', '') === '1';

        return Inertia::render('Products/Index', [
            'products'  => $products,
            'srEnabled' => $srEnabled,
        ]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * POST /products
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'page_url' => UrlNormalizer::normalize($request->input('page_url')),
        ]);

        $data = $request->validate(array_merge([
            'name'       => ['required', 'string', 'max:255'],
            'page_url'   => ['nullable', 'url', 'max:500'],
            'stock'      => ['required_without:variations', 'nullable', 'integer', 'min:0'],
            'weight'     => ['required', 'numeric', 'min:0'],
            'sr_item_id' => ['nullable', 'integer', 'min:1'],
            'variations'          => ['nullable', 'array'],
            'variations.*.number' => ['required', 'integer', 'min:1', 'distinct'],
            'variations.*.name'   => ['required', 'string', 'max:255'],
            'variations.*.stock'  => ['required', 'integer', 'min:0'],
        ], static::offerFieldRules()));

        $variations = array_values($data['variations'] ?? []);
        unset($data['variations']);

        if ($variations !== [] && !in_array(1, array_map(static fn ($v) => (int) $v['number'], $variations), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Среди вариаций должна быть вариация с номером 1',
            ], 422);
        }

        $tenantId = Auth::user()->tenant_id;

        if (Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', $data['name'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Товар с таким названием уже существует',
            ], 422);
        }

        $product = DB::transaction(function () use ($data, $tenantId, $variations) {
            $data['stock'] = $variations !== []
                ? array_sum(array_column($variations, 'stock'))
                : (int) $data['stock'];

            $product = Product::create(array_merge($data, ['tenant_id' => $tenantId]));

            $service = app(ProductVariationService::class);
            foreach ($variations as $v) {
                $service->create($product, (int) $v['number'], trim($v['name']), (int) $v['stock']);
            }

            return $product;
        });

        return response()->json(['success' => true, 'product' => $product->load('variations')]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * PUT /products/{product}
     * Update name, weight, or add stock (приход товара).
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        if ($request->has('page_url')) {
            $request->merge([
                'page_url' => UrlNormalizer::normalize($request->input('page_url')),
            ]);
        }

        $data = $request->validate(array_merge([
            'name'        => ['sometimes', 'string', 'max:255'],
            'page_url'    => ['nullable', 'url', 'max:500'],
            'weight'      => ['sometimes', 'numeric', 'min:0'],
            'stock_delta' => ['sometimes', 'integer'],
            'sr_item_id'  => ['nullable', 'integer', 'min:1'],
        ], static::offerFieldRules()));

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

        if (array_key_exists('page_url', $data)) {
            $product->page_url = $data['page_url'] ?: null;
        }

        if (isset($data['weight'])) {
            $product->weight = $data['weight'];
        }

        if (array_key_exists('sr_item_id', $data)) {
            $product->sr_item_id = $data['sr_item_id'] ?: null;
        }

        static::applyOfferFields($product, $data);

        // Stock intake (приход товара): positive delta adds, negative subtracts
        if (isset($data['stock_delta'])) {
            if (app(ProductVariationService::class)->isVariational($product)) {
                return response()->json([
                    'success' => false,
                    'message' => 'У товара есть вариации: остаток меняется по каждой вариации',
                ], 422);
            }
            $product->stock = max(0, $product->stock + (int)$data['stock_delta']);
        }

        $product->save();

        return response()->json(['success' => true, 'product' => $product->fresh('variations')]);
    }

    // ─── Variations ───────────────────────────────────────────────────────────

    /**
     * POST /products/{product}/variations
     * First variation turns the product into a variational one: variation 1 inherits the
     * current stock and sales.
     */
    public function storeVariation(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1'],
            'name'   => ['required', 'string', 'max:255'],
            'stock'  => ['nullable', 'integer', 'min:0'],
        ]);

        $service = app(ProductVariationService::class);

        if ($service->find($product, (int) $data['number'])) {
            return response()->json([
                'success' => false,
                'message' => 'Вариация с таким номером уже есть',
            ], 422);
        }

        DB::transaction(function () use ($product, $data, $service) {
            $locked = Product::lockForUpdate()->findOrFail($product->id);

            if (!$service->isVariational($locked)) {
                $service->makeVariational($locked);
            }

            if ((int) $data['number'] === 1) {
                $base = $service->find($locked, 1);
                $base->name = trim($data['name']);
                $base->save();
            } else {
                $service->create($locked, (int) $data['number'], trim($data['name']), (int) ($data['stock'] ?? 0));
            }

            $service->recalcProductTotals($locked);
        });

        return response()->json(['success' => true, 'product' => $product->fresh('variations')]);
    }

    /**
     * PUT /products/{product}/variations/{variation}
     * name and/or stock_delta (приход / списание вручную).
     */
    public function updateVariation(Request $request, Product $product, ProductVariation $variation): JsonResponse
    {
        abort_if($variation->product_id !== $product->id, 404);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'stock_delta' => ['sometimes', 'integer'],
        ]);

        DB::transaction(function () use ($product, $variation, $data) {
            $variation = ProductVariation::lockForUpdate()->findOrFail($variation->id);

            if (isset($data['name'])) {
                $variation->name = trim($data['name']);
            }
            if (isset($data['stock_delta'])) {
                $variation->stock = max(0, $variation->stock + (int) $data['stock_delta']);
            }
            $variation->save();

            app(ProductVariationService::class)->recalcProductTotals($product);
        });

        return response()->json(['success' => true, 'product' => $product->fresh('variations')]);
    }

    /**
     * DELETE /products/{product}/variations/{variation}
     * Only a variation with no stock, no sales and no stock movements, and never variation 1.
     */
    public function destroyVariation(Product $product, ProductVariation $variation): JsonResponse
    {
        abort_if($variation->product_id !== $product->id, 404);

        $used = $variation->stock > 0
            || $variation->sold_count > 0
            || OrderStockMovement::withoutGlobalScopes()->where('variation_id', $variation->id)->exists();

        if ($variation->number === 1 || $used) {
            return response()->json([
                'success' => false,
                'message' => $variation->number === 1
                    ? 'Вариацию 1 удалить нельзя'
                    : 'Нельзя удалить вариацию с остатком, продажами или движениями',
            ], 422);
        }

        $variation->delete();

        return response()->json(['success' => true, 'product' => $product->fresh('variations')]);
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

    /** @return array<string, array<int, string>> */
    protected static function offerFieldRules(): array
    {
        return [
            'upsell_name'  => ['nullable', 'string', 'max:255'],
            'upsell_price' => ['nullable', 'numeric', 'min:0'],
            'upsell_text'  => ['nullable', 'string', 'max:2000'],
            'cross_name'   => ['nullable', 'string', 'max:255'],
            'cross_price'  => ['nullable', 'numeric', 'min:0'],
            'cross_text'   => ['nullable', 'string', 'max:2000'],
            'manager_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected static function applyOfferFields(Product $product, array $data): void
    {
        foreach (['upsell_name', 'upsell_text', 'cross_name', 'cross_text', 'manager_note'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $product->{$key} = ($value === null || $value === '') ? null : $value;
            }
        }

        foreach (['upsell_price', 'cross_price'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $product->{$key} = ($value === null || $value === '') ? null : $value;
            }
        }
    }
}
