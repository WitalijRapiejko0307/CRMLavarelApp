<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Rules\FullNameTwoParts;
use App\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Store customer base keyed by the last 9 digits of the phone (per tenant).
 *
 * - Every order with a valid phone is linked to a customer (orders.customer_id).
 * - The customer's profile (name, address, delivery) is taken ONLY from the last
 *   successful order, so spam / refusals never pollute it.
 * - Auto-fill copies profile data into an order but never touches goods, prices,
 *   comments, status, source or UTM.
 *
 * All queries use explicit tenant ids (no reliance on TenantScope), because the
 * call-center users and queue jobs work with other tenants' orders.
 */
class CustomerService
{
    /** Orders whose data may feed the customer profile. */
    public const SUCCESS_STATUSES = [
        'Заказать',
        'Подтвержден',
        'Отправить',
        'Оформлен',
        'Передан на почту',
        'Отправлено',
        'Передан курьеру',
        'В отделении',
        'Забрать деньги',
        'Завершен',
    ];

    /** Statuses the operator must be warned about. */
    public const PROBLEM_STATUSES = [
        'Возврат',
        'Возврат в пути',
        'Отказ',
        'Отказ(Ошибка)',
        'Спам',
    ];

    public const BLACKLIST_MARK = 'НЕБЛАГОНАДЕЖНЫЙ КЛИЕНТ';

    public const ADDRESS_FIELDS = ['city', 'street', 'building', 'housing', 'apartment', 'poste_restante'];

    public const DELIVERY_FIELDS = ['delivery_type', 'belpost_address_id', 'europochta_store_id'];

    // ─── Keys ────────────────────────────────────────────────────────────────

    /** Lookup key, or '' when the phone is not a full 9-digit local number. */
    public static function phoneKey(?string $phone): string
    {
        $key = PhoneNormalizer::lastNineDigits($phone);

        return strlen($key) === 9 ? $key : '';
    }

    public static function cleanName(?string $name): string
    {
        $name = str_replace(self::BLACKLIST_MARK, '', (string) $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public function find(int $tenantId, ?string $phone): ?Customer
    {
        $key = self::phoneKey($phone);
        if ($key === '') {
            return null;
        }

        return Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone_key', $key)
            ->first();
    }

    /** True when the customer has profile data taken from a successful order. */
    public static function hasProfile(?Customer $customer): bool
    {
        return $customer !== null && $customer->last_order_id !== null;
    }

    // ─── Linking orders to customers ─────────────────────────────────────────

    /**
     * Find or create the customer for the order's phone and set orders.customer_id.
     */
    public function linkOrder(Order $order): ?Customer
    {
        $key = self::phoneKey($order->phone);

        if ($key === '' || !$order->tenant_id) {
            if ($order->customer_id) {
                Order::withoutGlobalScopes()->whereKey($order->id)->update(['customer_id' => null]);
                $order->setAttribute('customer_id', null);
            }

            return null;
        }

        $customer = $this->findOrCreate((int) $order->tenant_id, $key, $order->phone);

        if ((int) $order->customer_id !== (int) $customer->id) {
            Order::withoutGlobalScopes()->whereKey($order->id)->update(['customer_id' => $customer->id]);
            $order->setAttribute('customer_id', $customer->id);
            $order->syncOriginalAttribute('customer_id');
        }

        $this->refreshOrdersCount($customer);

        return $customer;
    }

    private function findOrCreate(int $tenantId, string $key, ?string $phone): Customer
    {
        $existing = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone_key', $key)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Customer::withoutGlobalScopes()->create([
                'tenant_id'  => $tenantId,
                'phone_key'  => $key,
                'phone'      => PhoneNormalizer::normalize($phone),
            ]);
        } catch (QueryException $e) {
            // Concurrent insert (unique tenant_id + phone_key): read the winner.
            return Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('phone_key', $key)
                ->firstOrFail();
        }
    }

    public function refreshOrdersCount(Customer $customer): void
    {
        $count = Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'Дубль')
            ->count();

        if ((int) $customer->orders_count !== $count) {
            $customer->forceFill(['orders_count' => $count])->saveQuietly();
        }
    }

    // ─── Profile ─────────────────────────────────────────────────────────────

    /**
     * Update the profile from a successful order. An older order never overrides
     * data from a newer one. Address and delivery blocks are copied as a unit.
     */
    public function syncProfileFromOrder(Order $order): void
    {
        if (!in_array($order->status, self::SUCCESS_STATUSES, true)) {
            return;
        }

        $customer = $order->customer_id
            ? Customer::withoutGlobalScopes()->find($order->customer_id)
            : $this->linkOrder($order);

        if (!$customer || (int) $customer->tenant_id !== (int) $order->tenant_id) {
            return;
        }

        if ($customer->last_order_id !== null && (int) $order->id < (int) $customer->last_order_id) {
            return;
        }

        $data = [
            'last_order_id' => $order->id,
            'last_order_at' => $order->created_at ?? Carbon::now(),
        ];

        $name = self::cleanName($order->full_name);
        if ($name !== '') {
            $data['full_name'] = $name;
        }

        if ($this->hasAddress($order)) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $data[$field] = $order->{$field};
            }
            $data['poste_restante'] = (bool) $order->poste_restante;
        }

        if ($order->delivery_type) {
            $data['delivery_type']       = $order->delivery_type;
            $data['belpost_address_id']  = $order->belpost_address_id;
            $data['europochta_store_id'] = $order->europochta_store_id;
        }

        $customer->forceFill($data)->saveQuietly();
    }

    private function hasAddress(Order $order): bool
    {
        if ($order->poste_restante && trim((string) $order->city) !== '') {
            return true;
        }

        return trim((string) $order->city) !== '' || trim((string) $order->street) !== '';
    }

    private function profileHasAddress(Customer $customer): bool
    {
        return trim((string) $customer->city) !== '' || trim((string) $customer->street) !== '';
    }

    // ─── Auto-fill ───────────────────────────────────────────────────────────

    /**
     * Fields that could be copied from the profile into the given order data
     * (an Order or a plain array of current form values). Never contains goods,
     * quantities, prices, comment, upsell, cross_sell, status, source or UTM.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function prefillValues(Customer $customer, array $current, bool $deliveryUntouched = true): array
    {
        $values = [];

        $currentName = (string) ($current['full_name'] ?? '');
        $cleanCurrent = self::cleanName($currentName);

        if (trim((string) $customer->full_name) !== ''
            && ($cleanCurrent === '' || !(new FullNameTwoParts)->passes('full_name', $cleanCurrent))) {
            // Keep the blacklist mark that the webhook may have added to the lead name.
            $mark = str_contains($currentName, self::BLACKLIST_MARK) ? ' ' . self::BLACKLIST_MARK : '';
            $values['full_name'] = $customer->full_name . $mark;
        }

        $addressEmpty = true;
        foreach (['city', 'street', 'building', 'housing', 'apartment'] as $field) {
            if (trim((string) ($current[$field] ?? '')) !== '') {
                $addressEmpty = false;
            }
        }
        if (!empty($current['poste_restante'])) {
            $addressEmpty = false;
        }

        if ($addressEmpty && $this->profileHasAddress($customer)) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $values[$field] = $customer->{$field};
            }
            $values['poste_restante'] = (bool) $customer->poste_restante;
        }

        if ($deliveryUntouched && $customer->delivery_type
            && empty($current['belpost_address_id'])
            && empty($current['europochta_store_id'])
            && in_array($current['delivery_type'] ?? null, [null, '', 'belpost'], true)) {
            foreach (self::DELIVERY_FIELDS as $field) {
                $values[$field] = $customer->{$field};
            }
        }

        return $values;
    }

    /**
     * Server-side auto-fill of a freshly created order (webhook, CSV import).
     * Runs once per phone: when the order was already looked up for this key,
     * nothing is searched again.
     *
     * @return list<string>  names of the filled fields
     */
    public function applyPrefill(Order $order): array
    {
        $key = self::phoneKey($order->phone);
        if ($key === '' || !$order->tenant_id) {
            return [];
        }

        if ($order->customer_lookup_at !== null && $order->customer_lookup_key === $key) {
            return [];
        }

        $customer = $order->customer_id
            ? Customer::withoutGlobalScopes()->find($order->customer_id)
            : $this->find((int) $order->tenant_id, $order->phone);

        $update = [
            'customer_lookup_at'  => Carbon::now(),
            'customer_lookup_key' => $key,
        ];
        $filled = [];

        if (self::hasProfile($customer) && (int) $customer->last_order_id !== (int) $order->id) {
            $values = $this->prefillValues($customer, $order->getAttributes());
            $filled = array_keys($values);

            if ($filled !== []) {
                $update = array_merge($update, $values, [
                    'customer_prefill' => [
                        'source_order_id' => $customer->last_order_id,
                        'fields'          => $filled,
                        'applied_at'      => Carbon::now()->toIso8601String(),
                    ],
                    'customer_prefill_ack_at' => null,
                ]);
            }
        }

        $order->forceFill($update)->saveQuietly();

        return $filled;
    }

    /**
     * Bookkeeping for orders edited by an operator: when the phone key changed,
     * remember the new lookup and drop an auto-fill banner that no longer applies.
     * Does nothing (no search) if the phone key is the same as at the last lookup.
     */
    public function registerLookup(Order $order): void
    {
        $key = self::phoneKey($order->phone);

        if ($key === '' || ($order->customer_lookup_at !== null && $order->customer_lookup_key === $key)) {
            return;
        }

        $changedPhone = $order->customer_lookup_key !== null && $order->customer_lookup_key !== $key;

        $fill = [
            'customer_lookup_at'  => Carbon::now(),
            'customer_lookup_key' => $key,
        ];

        if ($changedPhone) {
            $fill['customer_prefill']        = null;
            $fill['customer_prefill_ack_at'] = null;
        }

        $order->forceFill($fill)->saveQuietly();
    }

    public function acknowledgePrefill(Order $order): void
    {
        if ($order->customer_prefill !== null && $order->customer_prefill_ack_at === null) {
            $order->forceFill(['customer_prefill_ack_at' => Carbon::now()])->saveQuietly();
        }
    }

    /**
     * SalesRender confirmation overwrote some of the auto-filled fields:
     * drop them from the banner so it does not claim they came from the base.
     *
     * @param  list<string>  $overwritten
     */
    public function markOverwritten(Order $order, array $overwritten): void
    {
        $prefill = $order->customer_prefill;
        if (!is_array($prefill) || empty($prefill['fields'])) {
            return;
        }

        $remaining = array_values(array_diff($prefill['fields'], $overwritten));

        if ($remaining === []) {
            $order->forceFill(['customer_prefill' => null, 'customer_prefill_ack_at' => null])->saveQuietly();

            return;
        }

        $prefill['fields'] = $remaining;
        $order->forceFill(['customer_prefill' => $prefill])->saveQuietly();
    }

    // ─── Read model for the UI ───────────────────────────────────────────────

    /**
     * Summary for the order form: profile for auto-fill, history warnings.
     *
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, ?string $phone, ?int $excludeOrderId = null): array
    {
        $customer = $this->find($tenantId, $phone);

        if (!$customer) {
            return [
                'found'       => false,
                'has_profile' => false,
                'customer'    => null,
                'last_order'  => null,
                'problems'    => [],
                'blacklisted' => false,
            ];
        }

        $problems = Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::PROBLEM_STATUSES)
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'full_name', 'status', 'created_at', 'track_number'])
            ->map(fn (Order $row) => [
                'id'           => $row->id,
                'full_name'    => self::cleanName($row->full_name),
                'status'       => $row->status,
                'created_at'   => optional($row->created_at)->toIso8601String(),
                'track_number' => $row->track_number,
            ])
            ->all();

        $blacklisted = Order::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('full_name', 'like', '%' . self::BLACKLIST_MARK . '%')
            ->exists();

        $lastOrder = null;
        if (self::hasProfile($customer)) {
            $last = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($customer->last_order_id, ['id', 'goods', 'status', 'created_at']);

            if ($last) {
                $lastOrder = [
                    'id'         => $last->id,
                    'goods'      => $last->goods ?? [],
                    'status'     => $last->status,
                    'created_at' => optional($last->created_at)->toIso8601String(),
                ];
            }
        }

        return [
            'found'       => true,
            'has_profile' => self::hasProfile($customer),
            'customer'    => [
                'id'                  => $customer->id,
                'phone'               => $customer->phone,
                'full_name'           => $customer->full_name,
                'city'                => $customer->city,
                'street'              => $customer->street,
                'building'            => $customer->building,
                'housing'             => $customer->housing,
                'apartment'           => $customer->apartment,
                'poste_restante'      => (bool) $customer->poste_restante,
                'delivery_type'       => $customer->delivery_type,
                'belpost_address_id'  => $customer->belpost_address_id,
                'europochta_store_id' => $customer->europochta_store_id,
                'orders_count'        => $customer->orders_count,
            ],
            'last_order'  => $lastOrder,
            'problems'    => $problems,
            'blacklisted' => $blacklisted,
        ];
    }
}
