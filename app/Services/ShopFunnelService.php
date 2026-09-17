<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OrderSegment;
use Illuminate\Support\Collection;

class ShopFunnelService
{
    /**
     * Snapshot of current status. Each order is in at most one step.
     * Оформлено + Отправлено + ОПС + Выкуп = Заявка − статусы вне этих шагов
     * (Позвонить, Отправить, Отказ, Возврат, Забрать деньги, …).
     */
    private const FORMALIZED_STATUSES = [
        'Оформлен',
    ];

    private const SHIPPED_STATUSES = [
        'Отправлено',
    ];

    private const OPS_STATUSES = [
        'В отделении',
    ];

    /** Purchased never includes «Забрать деньги». */
    private const PURCHASED_STATUSES = [
        'Завершен',
        'Посчитан',
    ];

    private const LIVE_SLUGS = [
        OrderSegment::TO_SHIP_BELPOST,
        OrderSegment::TO_SHIP_EUROPOCHTA,
        OrderSegment::IN_TRANSIT,
        OrderSegment::STUCK,
    ];

    /**
     * @return array{
     *     funnel: list<array{key: string, label: string, count: int, rate: float}>,
     *     live: list<array{key: string, label: string, count: int, href: string}>,
     *     filters: array{date_from: ?string, date_to: ?string, utm_campaign: ?string},
     *     utm_campaigns: list<string>,
     * }
     */
    public function forStore(int $tenantId, ?string $dateFrom, ?string $dateTo, ?string $utmCampaign): array
    {
        $dateFrom = $dateFrom ?: null;
        $dateTo = $dateTo ?: null;
        $utmCampaign = $utmCampaign ?: null;

        $countsByStatus = $this->cohortStatusCounts($tenantId, $dateFrom, $dateTo, $utmCampaign);
        $leads = (int) $countsByStatus->sum();
        $formalized = $this->sumStatuses($countsByStatus, self::FORMALIZED_STATUSES);
        $shipped = $this->sumStatuses($countsByStatus, self::SHIPPED_STATUSES);
        $ops = $this->sumStatuses($countsByStatus, self::OPS_STATUSES);
        $purchased = $this->sumStatuses($countsByStatus, self::PURCHASED_STATUSES);

        return [
            'funnel' => [
                $this->step('leads', 'Заявка', $leads, $leads),
                $this->step('formalized', 'Оформлено', $formalized, $leads),
                $this->step('shipped', 'Отправлено', $shipped, $leads),
                $this->step('ops', 'ОПС', $ops, $leads),
                $this->step('purchased', 'Выкуп', $purchased, $leads),
            ],
            'live' => $this->liveSlice($tenantId),
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'utm_campaign' => $utmCampaign,
            ],
            'utm_campaigns' => $this->utmCampaigns($tenantId),
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function cohortStatusCounts(int $tenantId, ?string $dateFrom, ?string $dateTo, ?string $utmCampaign): Collection
    {
        $query = Order::withoutGlobalScopes()->where('tenant_id', $tenantId);
        Order::funnelBaseQuery($query);

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($utmCampaign) {
            $query->where('utm_campaign', $utmCampaign);
        }

        return $query
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->map(fn ($cnt) => (int) $cnt);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function sumStatuses(Collection $countsByStatus, array $statuses): int
    {
        return (int) $countsByStatus->only($statuses)->sum();
    }

    /**
     * @return array{key: string, label: string, count: int, rate: float}
     */
    private function step(string $key, string $label, int $count, int $leads): array
    {
        $rate = $leads === 0
            ? 0.0
            : ($key === 'leads' ? 100.0 : round($count / $leads * 100, 1));

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'rate' => $rate,
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function liveSlice(int $tenantId): array
    {
        $labels = OrderSegment::labels();

        return array_map(function (string $slug) use ($tenantId, $labels) {
            $query = Order::withoutGlobalScopes()->where('tenant_id', $tenantId);

            return [
                'key' => $slug,
                'label' => $labels[$slug],
                'count' => OrderSegment::apply($query, $slug)->count(),
                'href' => '/orders?segment=' . $slug,
            ];
        }, self::LIVE_SLUGS);
    }

    /**
     * @return list<string>
     */
    private function utmCampaigns(int $tenantId): array
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('utm_campaign')
            ->where('utm_campaign', '!=', '')
            ->distinct()
            ->orderBy('utm_campaign')
            ->pluck('utm_campaign')
            ->values()
            ->all();
    }
}
