<?php

namespace App\Support;

use App\Models\Order;

/**
 * Comparable ("normalized") representations of order fields on the CRM and SalesRender side.
 *
 * Fields: phone (string, international digits), full_name (string), cart (list of lines), status (CRM status).
 */
final class SrOrderMapper
{
    public const FIELDS = ['phone', 'full_name', 'cart', 'status'];

    // ─── CRM side ─────────────────────────────────────────────────────────────

    /** @return mixed */
    public static function crmValue(Order $order, string $field)
    {
        switch ($field) {
            case 'phone':
                return PhoneNormalizer::toInternationalDigits($order->phone);
            case 'full_name':
                return self::collapse((string) $order->full_name);
            case 'cart':
                return self::crmCart($order);
            case 'status':
                return (string) $order->status;
        }

        return null;
    }

    /** @return list<array{name:string, qty:int, price:float, var:?int}> */
    public static function crmCart(Order $order): array
    {
        $goods      = array_values($order->goods ?? []);
        $quantities = array_values($order->quantities ?? []);
        $prices     = array_values($order->prices ?? []);
        $variations = $order->variationsAligned();

        $lines = [];
        foreach ($goods as $i => $name) {
            $number  = $variations[$i]['number'] ?? null;
            $lines[] = [
                'name'  => self::collapse((string) $name),
                'qty'   => (int) ($quantities[$i] ?? 1),
                'price' => (float) ($prices[$i] ?? 0),
                'var'   => $number !== null && (int) $number > 1 ? (int) $number : null,
            ];
        }

        return $lines;
    }

    // ─── SalesRender side ─────────────────────────────────────────────────────

    /** @return mixed */
    public static function srValue(array $srOrder, string $field)
    {
        switch ($field) {
            case 'phone':
                return (string) ($srOrder['data']['phoneFields'][0]['value']['raw'] ?? '');
            case 'full_name':
                $v = $srOrder['data']['humanNameFields'][0]['value'] ?? [];

                return self::collapse(trim(($v['firstName'] ?? '') . ' ' . ($v['lastName'] ?? '')));
            case 'cart':
                return self::srCart($srOrder);
            case 'status':
                return (string) ($srOrder['status']['name'] ?? '');
        }

        return null;
    }

    /** @return list<array{name:string, qty:int, price:float, var:?int}> */
    public static function srCart(array $srOrder): array
    {
        $lines = [];
        foreach ($srOrder['cart']['items'] ?? [] as $item) {
            $number  = $item['sku']['variation']['number'] ?? null;
            $lines[] = [
                'name'  => self::collapse((string) ($item['sku']['item']['name'] ?? '')),
                'qty'   => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['pricing']['unitPrice'] ?? 0),
                'var'   => $number !== null && (int) $number > 1 ? (int) $number : null,
            ];
        }

        return $lines;
    }

    // ─── Comparison ───────────────────────────────────────────────────────────

    /**
     * @param  mixed  $a
     * @param  mixed  $b
     */
    public static function equal(string $field, $a, $b): bool
    {
        switch ($field) {
            case 'phone':
                $x = PhoneNormalizer::lastNineDigits((string) $a);

                return $x !== '' && $x === PhoneNormalizer::lastNineDigits((string) $b);
            case 'full_name':
                return mb_strtolower(self::collapse((string) $a)) === mb_strtolower(self::collapse((string) $b));
            case 'cart':
                return self::cartEqual((array) $a, (array) $b);
            case 'status':
                return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    /**
     * @param  list<array>  $a
     * @param  list<array>  $b
     */
    public static function cartEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach (array_values($a) as $i => $line) {
            $other = array_values($b)[$i];

            if (mb_strtolower((string) ($line['name'] ?? '')) !== mb_strtolower((string) ($other['name'] ?? ''))
                || (int) ($line['qty'] ?? 0) !== (int) ($other['qty'] ?? 0)
                || abs((float) ($line['price'] ?? 0) - (float) ($other['price'] ?? 0)) >= 0.005
                || ($line['var'] ?? null) !== ($other['var'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Short human text of a value for notices and the conflict dialog. */
    public static function label(string $field, $value): string
    {
        if ($field === 'cart') {
            $parts = [];
            foreach ((array) $value as $line) {
                $parts[] = ($line['name'] ?? '') . ' × ' . ($line['qty'] ?? 1) . ' (' . rtrim(rtrim(number_format((float) ($line['price'] ?? 0), 2, '.', ''), '0'), '.') . ')';
            }

            return $parts ? implode('; ', $parts) : '—';
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : '—';
    }
}
