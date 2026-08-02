<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;

class ProductLinkResolver
{
    /**
     * Resolve live catalog URLs for order line items by product name.
     *
     * @return array<string, string|null> good name => page_url
     */
    public static function forOrder(Order $order): array
    {
        $goods = $order->goods ?? [];

        if ($goods === []) {
            return [];
        }

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->whereIn('name', $goods)
            ->pluck('page_url', 'name');

        $links = [];

        foreach ($goods as $name) {
            $links[$name] = $products->get($name) ?: null;
        }

        return $links;
    }
}
