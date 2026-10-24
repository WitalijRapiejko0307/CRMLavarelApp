<?php

namespace App\Support;

use App\Models\TenantOrderColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantOrderListColumns
{
    /** @var array<string, string> field key => short Russian header */
    public const FIELDS = [
        'city'           => 'Город',
        'street'         => 'Улица',
        'comment'        => 'Комментарий',
        'source'         => 'Источник',
        'prices'         => 'Цена',
        'order_total'    => 'Сумма',
        'upsell'         => 'Upsell',
        'cross_sell'     => 'Cross-sell',
        'poste_restante' => 'До востребования',
    ];

    /** @return list<string> */
    public static function whitelist(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return list<array{key: string, label: string}> */
    public static function optionsForUi(): array
    {
        $options = [];
        foreach (self::FIELDS as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label];
        }

        return $options;
    }

    /** @return list<string> */
    public static function fieldsForTenant(int $tenantId): array
    {
        return TenantOrderColumn::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->pluck('field')
            ->all();
    }

    /**
     * @param  list<string>  $fields
     */
    public static function saveForTenant(int $tenantId, array $fields): void
    {
        $allowed = self::whitelist();
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                continue;
            }
            if (!in_array($field, $normalized, true)) {
                $normalized[] = $field;
            }
        }

        DB::transaction(function () use ($tenantId, $normalized): void {
            TenantOrderColumn::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->delete();

            $position = 0;
            foreach ($normalized as $field) {
                TenantOrderColumn::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'field'     => $field,
                    'position'  => $position++,
                ]);
            }
        });
    }

    /**
     * @return array<int, string|\Illuminate\Validation\Rules\In>
     */
    public static function fieldValidationRules(): array
    {
        return ['string', Rule::in(self::whitelist())];
    }
}
