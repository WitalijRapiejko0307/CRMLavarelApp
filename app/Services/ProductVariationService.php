<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single place for product-variation rules (see docs/features/product-variations.md).
 *
 * - A variation is identified by (product_id, number); `number` is the SalesRender variation number.
 * - A product with at least one variation row is "variational": Product.stock / sold_* are the
 *   sums over its variations.
 * - Number 1 without a row means "no variation" (SalesRender always has variation 1 = "Основная").
 * - For a variational product a missing variation (null) means variation 1.
 */
class ProductVariationService
{
    public function find(Product $product, int $number): ?ProductVariation
    {
        return ProductVariation::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('number', $number)
            ->first();
    }

    /**
     * Webhook input: the variation NAME (like the offer, matched by name), case-insensitive and
     * trimmed. Always a name, even if it looks like a number (size "42"). Never creates variations;
     * unknown input returns null.
     */
    public function findByName(Product $product, string $input): ?ProductVariation
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $needle = mb_strtolower($input);

        return ProductVariation::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->get()
            ->first(fn (ProductVariation $v) => mb_strtolower(trim((string) $v->name)) === $needle);
    }

    public function isVariational(Product $product): bool
    {
        return ProductVariation::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->exists();
    }

    /**
     * Resolve an incoming variation number (webhook / SalesRender) to a variation row.
     * Creates the variation when the number is unknown and is not 1.
     * Returns null for "no variation".
     */
    public function resolve(Product $product, ?int $number, ?string $property = null): ?ProductVariation
    {
        if ($number === null || $number < 1) {
            return null;
        }

        $existing = $this->find($product, $number);
        if ($existing) {
            return $this->fillName($existing, $property);
        }

        if ($number === 1) {
            return null;
        }

        try {
            return DB::transaction(function () use ($product, $number, $property) {
                $locked = Product::withoutGlobalScopes()->lockForUpdate()->find($product->id);
                if (!$locked) {
                    return null;
                }

                $again = $this->find($locked, $number);
                if ($again) {
                    return $this->fillName($again, $property);
                }

                if (!$this->isVariational($locked)) {
                    $this->makeVariational($locked);
                }

                return $this->create($locked, $number, $this->cleanName($property));
            });
        } catch (QueryException $e) {
            // Concurrent request created the same number first.
            $existing = $this->find($product, $number);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Turn a plain product into a variational one: variation 1 inherits the current
     * stock and sales of the product.
     */
    public function makeVariational(Product $product): ProductVariation
    {
        $existing = $this->find($product, 1);
        if ($existing) {
            return $existing;
        }

        return ProductVariation::withoutGlobalScopes()->create([
            'tenant_id'   => $product->tenant_id,
            'product_id'  => $product->id,
            'number'      => 1,
            'name'        => null,
            'stock'       => (int) $product->stock,
            'sold_count'  => (int) $product->sold_count,
            'sold_amount' => (float) $product->sold_amount,
        ]);
    }

    public function create(Product $product, int $number, ?string $name = null, int $stock = 0): ProductVariation
    {
        return ProductVariation::withoutGlobalScopes()->create([
            'tenant_id'  => $product->tenant_id,
            'product_id' => $product->id,
            'number'     => $number,
            'name'       => $name,
            'stock'      => $stock,
        ]);
    }

    /**
     * Variation row that stock / sales of an order line belong to, or null for a plain product.
     *
     * @param  array{number?: int}|null  $orderVariation  order.variations[i]
     */
    public function resolveForStock(Product $product, ?array $orderVariation): ?ProductVariation
    {
        if (!$this->isVariational($product)) {
            return null;
        }

        $number = (int) ($orderVariation['number'] ?? 1);
        $variation = $this->find($product, $number);

        if (!$variation && $number !== 1) {
            Log::warning('ProductVariationService: variation not found, using variation 1', [
                'product_id' => $product->id,
                'number'     => $number,
            ]);
            $variation = $this->find($product, 1);
        }

        return $variation;
    }

    /**
     * Product.stock / sold_* = sum over variations (no-op for a plain product).
     */
    public function recalcProductTotals(Product $product): void
    {
        $row = ProductVariation::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(stock),0) AS stock, COALESCE(SUM(sold_count),0) AS sold_count, COALESCE(SUM(sold_amount),0) AS sold_amount')
            ->first();

        if (!$row || (int) $row->c === 0) {
            return;
        }

        $values = [
            'stock'       => (int) $row->stock,
            'sold_count'  => (int) $row->sold_count,
            'sold_amount' => round((float) $row->sold_amount, 2),
        ];

        Product::withoutGlobalScopes()->whereKey($product->id)->update($values);
        $product->forceFill($values)->syncOriginal();
    }

    private function fillName(ProductVariation $variation, ?string $property): ProductVariation
    {
        $name = $this->cleanName($property);

        if ($name !== null && trim((string) $variation->name) === '') {
            $variation->name = $name;
            $variation->save();
        }

        return $variation;
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
