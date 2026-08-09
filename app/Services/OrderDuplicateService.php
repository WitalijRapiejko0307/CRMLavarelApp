<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Collection;

class OrderDuplicateService
{
    /**
     * Attach is_phone_duplicate and duplicate_of_order_id to each order in the collection.
     */
    public function attachDuplicateFlags(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $byTenant = $orders->groupBy('tenant_id');

        foreach ($byTenant as $tenantId => $tenantOrders) {
            $this->attachForTenant($tenantOrders, (int) $tenantId);
        }
    }

    /**
     * @return array{is_phone_duplicate: bool, duplicate_of_order_id: int|null}
     */
    public function flagsForOrder(Order $order): array
    {
        $suffix = PhoneNormalizer::lastNineDigits($order->phone);

        if ($suffix === '') {
            return ['is_phone_duplicate' => false, 'duplicate_of_order_id' => null];
        }

        $duplicateId = Order::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->where('id', '!=', $order->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone'])
            ->filter(fn (Order $o) => PhoneNormalizer::lastNineDigits($o->phone) === $suffix)
            ->min('id');

        return [
            'is_phone_duplicate'    => $duplicateId !== null,
            'duplicate_of_order_id' => $duplicateId,
        ];
    }

    private function attachForTenant(Collection $orders, int $tenantId): void
    {
        $suffixMap = [];

        foreach ($orders as $order) {
            $suffix = PhoneNormalizer::lastNineDigits($order->phone);
            if ($suffix !== '') {
                $suffixMap[$order->id] = $suffix;
            }
        }

        if ($suffixMap === []) {
            foreach ($orders as $order) {
                $order->setAttribute('is_phone_duplicate', false);
                $order->setAttribute('duplicate_of_order_id', null);
            }

            return;
        }

        $uniqueSuffixes = array_values(array_unique(array_values($suffixMap)));

        $candidates = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone']);

        $minIdBySuffix = [];

        foreach ($candidates as $candidate) {
            $suffix = PhoneNormalizer::lastNineDigits($candidate->phone);
            if ($suffix === '' || !in_array($suffix, $uniqueSuffixes, true)) {
                continue;
            }

            $current = $minIdBySuffix[$suffix] ?? PHP_INT_MAX;
            if ($candidate->id < $current) {
                $minIdBySuffix[$suffix] = $candidate->id;
            }
        }

        foreach ($orders as $order) {
            $suffix = $suffixMap[$order->id] ?? null;

            if ($suffix === null || !isset($minIdBySuffix[$suffix])) {
                $order->setAttribute('is_phone_duplicate', false);
                $order->setAttribute('duplicate_of_order_id', null);
                continue;
            }

            $minId = $minIdBySuffix[$suffix];
            $isDuplicate = $minId < $order->id;

            $order->setAttribute('is_phone_duplicate', $isDuplicate);
            $order->setAttribute(
                'duplicate_of_order_id',
                $isDuplicate ? $minId : null
            );
        }
    }
}
