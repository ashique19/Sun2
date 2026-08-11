<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live investor-pitch metrics from current order/product data (Asia/Dhaka).
 */
class InvestorPitchAnalyticsService
{
    /**
     * @return array{
     *     as_of: string,
     *     year: int,
     *     prior_year: int,
     *     is_partial_year: bool,
     *     window: array{start: string, end: string, label: string},
     *     prior_window: array{start: string, end: string, label: string},
     *     traction: array<string, mixed>,
     *     prior: array<string, mixed>,
     *     growth: array<string, mixed>,
     *     unit_economics: array<string, mixed>,
     *     channels: list<array{via: string, orders: int, gmv: float, share_pct: float}>,
     *     geos: list<array{city: string, orders: int, gmv: float}>,
     *     categories: list<array{name: string, orders: int, revenue: float}>,
     *     monthly: list<array{month: int, label: string, orders: int, gmv: float, delivered: int, collected: float, dispatched: int, prior_gmv: float, prior_orders: int}>,
     *     caveats: list<string>
     * }
     */
    public function deck(int $year, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now('Asia/Dhaka'))->copy()->timezone('Asia/Dhaka');
        $currentYear = (int) $asOf->year;

        if ($year < 2000 || $year > $currentYear + 1) {
            $year = $currentYear;
        }

        $yearStart = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $yearEndFull = $yearStart->copy()->endOfYear();
        $isPartial = $year === $currentYear;
        $yearEnd = $isPartial ? $asOf->copy() : $yearEndFull;

        $priorYear = $year - 1;
        $priorStart = Carbon::create($priorYear, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        // Like-for-like: YTD selected year vs same calendar span last year.
        $priorEnd = $yearEnd->copy()->subYear();

        $traction = $this->windowMetrics($yearStart, $yearEnd);
        $prior = $this->windowMetrics($priorStart, $priorEnd);

        return [
            'as_of' => $asOf->format('Y-m-d H:i T'),
            'year' => $year,
            'prior_year' => $priorYear,
            'is_partial_year' => $isPartial,
            'window' => [
                'start' => $yearStart->toDateString(),
                'end' => $yearEnd->toDateString(),
                'label' => $isPartial ? "{$year} YTD" : (string) $year,
            ],
            'prior_window' => [
                'start' => $priorStart->toDateString(),
                'end' => $priorEnd->toDateString(),
                'label' => $isPartial ? "{$priorYear} same period" : (string) $priorYear,
            ],
            'traction' => $traction,
            'prior' => $prior,
            'growth' => $this->growth($traction, $prior),
            'unit_economics' => $this->unitEconomics($yearStart, $yearEnd, $traction),
            'channels' => $this->channels($yearStart, $yearEnd),
            'geos' => $this->geos($yearStart, $yearEnd),
            'categories' => $this->categories($yearStart, $yearEnd),
            'monthly' => $this->monthlyByYear($year, $yearStart, $yearEnd, $priorStart, $priorEnd),
            'caveats' => [
                $isPartial
                    ? "Selected year {$year} is year-to-date through {$yearEnd->toDateString()}; prior year uses the same calendar span for a fair YoY compare."
                    : "Selected year {$year} is a full calendar year versus full {$priorYear}.",
                'Merchandise gross margin uses lines with purchase_price > 0; zero-cost lines are excluded from GM%.',
                'Courier cost uses snapshotted courier_charge (estimated or confirmed); historical gaps remain where fee was never stored.',
                'Contribution is before ads, salaries, rent, and other opex not fully captured in expenses.',
                'Unsettled dispatched orders are excluded from delivered/collected until settlement is recorded.',
            ],
        ];
    }

    /**
     * Years that have placed orders (newest first), always including the current Dhaka year.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = Order::query()
            ->whereNotNull('placed_at')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->get(['placed_at'])
            ->map(fn (Order $order) => (int) $order->placed_at->timezone('Asia/Dhaka')->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        $current = (int) now('Asia/Dhaka')->year;

        if ($years === []) {
            return [$current];
        }

        if (! in_array($current, $years, true)) {
            array_unshift($years, $current);
        }

        return $years;
    }

    /**
     * @return array{
     *     orders: int,
     *     gmv_placed: float,
     *     delivered: int,
     *     gmv_delivered: float,
     *     collected: float,
     *     collection_pct: float,
     *     returned: int,
     *     return_pct: float,
     *     dispatched: int,
     *     unsettled_gmv: float,
     *     aov: float,
     *     unique_buyers: int
     * }
     */
    public function windowMetrics(Carbon $start, Carbon $end): array
    {
        $orders = $this->ordersInWindow($start, $end);

        $ordersCount = $orders->count();
        $gmvPlaced = round((float) $orders->sum(fn (Order $o) => (float) $o->total), 2);
        $delivered = $orders->where('status', 'delivered');
        $deliveredCount = $delivered->count();
        $gmvDelivered = round((float) $delivered->sum(fn (Order $o) => (float) $o->total), 2);
        $collected = round((float) $delivered->sum(fn (Order $o) => (float) $o->collected_amount), 2);
        $returned = $orders->where('status', 'returned')->count();
        $dispatched = $orders->where('status', 'dispatched');

        return [
            'orders' => $ordersCount,
            'gmv_placed' => $gmvPlaced,
            'delivered' => $deliveredCount,
            'gmv_delivered' => $gmvDelivered,
            'collected' => $collected,
            'collection_pct' => $gmvDelivered > 0
                ? round($collected * 100 / $gmvDelivered, 1)
                : 0.0,
            'returned' => $returned,
            'return_pct' => $ordersCount > 0
                ? round($returned * 100 / $ordersCount, 1)
                : 0.0,
            'dispatched' => $dispatched->count(),
            'unsettled_gmv' => round((float) $dispatched->sum(fn (Order $o) => (float) $o->total), 2),
            'aov' => $ordersCount > 0 ? round($gmvPlaced / $ordersCount, 0) : 0.0,
            'unique_buyers' => $orders->pluck('phone')->filter()->unique()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $prior
     * @return array<string, float|null>
     */
    private function growth(array $current, array $prior): array
    {
        return [
            'orders_pct' => $this->pctChange((float) $prior['orders'], (float) $current['orders']),
            'gmv_placed_pct' => $this->pctChange((float) $prior['gmv_placed'], (float) $current['gmv_placed']),
            'delivered_pct' => $this->pctChange((float) $prior['delivered'], (float) $current['delivered']),
            'collected_pct' => $this->pctChange((float) $prior['collected'], (float) $current['collected']),
            'aov_pct' => $this->pctChange((float) $prior['aov'], (float) $current['aov']),
        ];
    }

    /**
     * @param  array<string, mixed>  $traction
     * @return array<string, mixed>
     */
    private function unitEconomics(Carbon $start, Carbon $end, array $traction): array
    {
        $orders = $this->ordersInWindow($start, $end)->where('status', 'delivered');
        $orderIds = $orders->pluck('id');

        $delivIncome = round((float) $orders->sum(fn (Order $o) => (float) $o->delivery_charge), 2);
        $courierCost = round((float) $orders->sum(fn (Order $o) => (float) $o->courier_charge), 2);
        $packaging = round((float) $orders->sum(fn (Order $o) => (float) $o->packaging_cost), 2);

        $lines = $orderIds->isEmpty()
            ? collect()
            : OrderProduct::query()->whereIn('order_id', $orderIds)->get([
                'quantity', 'returned_quantity', 'price', 'purchase_price',
            ]);

        $merchSell = 0.0;
        $sellKnown = 0.0;
        $cogsKnown = 0.0;
        $zeroLines = 0;
        $lineCount = 0;

        foreach ($lines as $line) {
            $qty = max(0, (int) $line->quantity - (int) ($line->returned_quantity ?? 0));
            $sell = (float) $line->price * $qty;
            $merchSell += $sell;
            $lineCount++;
            $pp = (float) $line->purchase_price;

            if ($pp > 0) {
                $sellKnown += $sell;
                $cogsKnown += $pp * $qty;
            } else {
                $zeroLines++;
            }
        }

        $merchSell = round($merchSell, 2);
        $sellKnown = round($sellKnown, 2);
        $cogsKnown = round($cogsKnown, 2);
        $gmPct = $sellKnown > 0
            ? round(($sellKnown - $cogsKnown) * 100 / $sellKnown, 1)
            : null;
        $merchGpKnown = round($sellKnown - $cogsKnown, 2);
        $merchGpEst = $gmPct !== null
            ? round($merchSell * ($gmPct / 100), 2)
            : null;
        $deliveryMargin = round($delivIncome - $courierCost, 2);
        $contributionEst = $merchGpEst !== null
            ? round($merchGpEst + $deliveryMargin - $packaging, 2)
            : null;
        $collected = (float) $traction['collected'];

        return [
            'merch_sell' => $merchSell,
            'sell_known' => $sellKnown,
            'cogs_known' => $cogsKnown,
            'cogs_coverage_pct' => $lineCount > 0
                ? round(($lineCount - $zeroLines) * 100 / $lineCount, 1)
                : 0.0,
            'gm_pct_known' => $gmPct,
            'merch_gp_known' => $merchGpKnown,
            'merch_gp_est' => $merchGpEst,
            'delivery_income' => $delivIncome,
            'courier_cost' => $courierCost,
            'delivery_margin' => $deliveryMargin,
            'packaging' => $packaging,
            'contribution_est' => $contributionEst,
            'contribution_pct_of_collected' => ($contributionEst !== null && $collected > 0)
                ? round($contributionEst * 100 / $collected, 1)
                : null,
        ];
    }

    /**
     * @return list<array{via: string, orders: int, gmv: float, share_pct: float}>
     */
    private function channels(Carbon $start, Carbon $end): array
    {
        $orders = $this->ordersInWindow($start, $end);
        $total = max(1, $orders->count());

        return $orders
            ->groupBy(fn (Order $o) => $o->placed_via ?: '(unknown)')
            ->map(function (Collection $group, string $via) use ($total): array {
                return [
                    'via' => $via,
                    'orders' => $group->count(),
                    'gmv' => round((float) $group->sum(fn (Order $o) => (float) $o->total), 2),
                    'share_pct' => round($group->count() * 100 / $total, 1),
                ];
            })
            ->sortByDesc('orders')
            ->values()
            ->all();
    }

    /**
     * @return list<array{city: string, orders: int, gmv: float}>
     */
    private function geos(Carbon $start, Carbon $end): array
    {
        return $this->ordersInWindow($start, $end)
            ->groupBy(fn (Order $o) => trim((string) $o->city) !== '' ? trim((string) $o->city) : '(blank)')
            ->map(fn (Collection $group, string $city): array => [
                'city' => $city,
                'orders' => $group->count(),
                'gmv' => round((float) $group->sum(fn (Order $o) => (float) $o->total), 2),
            ])
            ->sortByDesc('orders')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, orders: int, revenue: float}>
     */
    private function categories(Carbon $start, Carbon $end): array
    {
        $orderIds = $this->ordersInWindow($start, $end)
            ->where('status', 'delivered')
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('order_products as op')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('op.order_id', $orderIds)
            ->groupBy('c.id', 'c.name')
            ->orderByDesc(DB::raw('SUM(op.price * (op.quantity - COALESCE(op.returned_quantity, 0)))'))
            ->limit(8)
            ->get([
                DB::raw('COALESCE(c.name, "(uncategorized)") as name'),
                DB::raw('COUNT(DISTINCT op.order_id) as orders'),
                DB::raw('SUM(op.price * (op.quantity - COALESCE(op.returned_quantity, 0))) as revenue'),
            ]);

        return $rows->map(fn ($row): array => [
            'name' => (string) $row->name,
            'orders' => (int) $row->orders,
            'revenue' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * Calendar months for the selected year, with prior-year same-month GMV for comparison.
     *
     * @return list<array{month: int, label: string, orders: int, gmv: float, delivered: int, collected: float, dispatched: int, prior_gmv: float, prior_orders: int}>
     */
    private function monthlyByYear(
        int $year,
        Carbon $start,
        Carbon $end,
        Carbon $priorStart,
        Carbon $priorEnd,
    ): array {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'orders' => 0,
                'gmv' => 0.0,
                'delivered' => 0,
                'collected' => 0.0,
                'dispatched' => 0,
                'prior_gmv' => 0.0,
                'prior_orders' => 0,
            ];
        }

        foreach ($this->ordersInWindow($start, $end) as $order) {
            $placed = $order->placed_at?->timezone('Asia/Dhaka');

            if ($placed === null) {
                continue;
            }

            $month = (int) $placed->month;
            $months[$month]['orders']++;
            $months[$month]['gmv'] += (float) $order->total;

            if ($order->status === 'delivered') {
                $months[$month]['delivered']++;
                $months[$month]['collected'] += (float) $order->collected_amount;
            }

            if ($order->status === 'dispatched') {
                $months[$month]['dispatched']++;
            }
        }

        foreach ($this->ordersInWindow($priorStart, $priorEnd) as $order) {
            $placed = $order->placed_at?->timezone('Asia/Dhaka');

            if ($placed === null) {
                continue;
            }

            $month = (int) $placed->month;
            $months[$month]['prior_orders']++;
            $months[$month]['prior_gmv'] += (float) $order->total;
        }

        // Hide future months in a partial current year (no activity expected).
        $lastMonth = (int) $end->timezone('Asia/Dhaka')->month;

        return array_values(array_map(function (array $row) use ($lastMonth, $end, $year): array {
            $row['gmv'] = round($row['gmv'], 2);
            $row['collected'] = round($row['collected'], 2);
            $row['prior_gmv'] = round($row['prior_gmv'], 2);

            if ($year === (int) $end->year && $row['month'] > $lastMonth) {
                $row['orders'] = 0;
                $row['gmv'] = 0.0;
                $row['delivered'] = 0;
                $row['collected'] = 0.0;
                $row['dispatched'] = 0;
            }

            return $row;
        }, array_filter($months, fn (array $row) => $year !== (int) $end->year || $row['month'] <= $lastMonth)));
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersInWindow(Carbon $start, Carbon $end): Collection
    {
        return Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [
                $start->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
                $end->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
            ])
            ->get([
                'id', 'status', 'total', 'collected_amount', 'delivery_charge',
                'courier_charge', 'packaging_cost', 'placed_at', 'created_at',
                'placed_via', 'phone', 'city',
            ]);
    }

    private function pctChange(float $prior, float $current): ?float
    {
        if ($prior == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(($current - $prior) * 100 / $prior, 1);
    }
}
