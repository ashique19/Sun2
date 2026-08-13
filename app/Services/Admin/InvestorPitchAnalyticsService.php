<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Support\DhakaSql;
use App\Support\OrderEconomicsSql;
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
     * Years that have non-draft orders by created_at (newest first), always including the current Dhaka year.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        return app(AnalyticsService::class)->availableYears();
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
        [$from, $to] = $this->windowBounds($start, $end);

        $row = DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total), 0) as gmv_placed')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) as delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END), 0) as gmv_delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN collected_amount ELSE 0 END), 0) as collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END), 0) as returned")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END), 0) as dispatched")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dispatched' THEN total ELSE 0 END), 0) as unsettled_gmv")
            ->selectRaw('COUNT(DISTINCT NULLIF(TRIM(phone), \'\')) as unique_buyers')
            ->first();

        $ordersCount = (int) ($row->orders ?? 0);
        $gmvPlaced = round((float) ($row->gmv_placed ?? 0), 2);
        $deliveredCount = (int) ($row->delivered ?? 0);
        $gmvDelivered = round((float) ($row->gmv_delivered ?? 0), 2);
        $collected = round((float) ($row->collected ?? 0), 2);
        $returned = (int) ($row->returned ?? 0);

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
            'dispatched' => (int) ($row->dispatched ?? 0),
            'unsettled_gmv' => round((float) ($row->unsettled_gmv ?? 0), 2),
            'aov' => $ordersCount > 0 ? round($gmvPlaced / $ordersCount, 0) : 0.0,
            'unique_buyers' => (int) ($row->unique_buyers ?? 0),
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
        [$from, $to] = $this->windowBounds($start, $end);

        $orderRow = DB::table('orders')
            ->where('status', 'delivered')
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(delivery_charge), 0) as delivery_income')
            ->selectRaw('COALESCE(SUM(courier_charge), 0) as courier_cost')
            ->selectRaw('COALESCE(SUM(packaging_cost), 0) as packaging')
            ->first();

        $qty = OrderEconomicsSql::greatest('(op.quantity - COALESCE(op.returned_quantity, 0))', '0');

        $lineTotals = DB::table('order_products as op')
            ->join('orders', 'orders.id', '=', 'op.order_id')
            ->where('orders.status', 'delivered')
            ->whereNotNull('orders.created_at')
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(op.price * {$qty}), 0) as merch_sell")
            ->selectRaw("COALESCE(SUM(CASE WHEN COALESCE(op.purchase_price, 0) > 0 THEN op.price * {$qty} ELSE 0 END), 0) as sell_known")
            ->selectRaw("COALESCE(SUM(CASE WHEN COALESCE(op.purchase_price, 0) > 0 THEN op.purchase_price * {$qty} ELSE 0 END), 0) as cogs_known")
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(op.purchase_price, 0) <= 0 THEN 1 ELSE 0 END), 0) as zero_lines')
            ->first();

        $merchSell = round((float) ($lineTotals->merch_sell ?? 0), 2);
        $sellKnown = round((float) ($lineTotals->sell_known ?? 0), 2);
        $cogsKnown = round((float) ($lineTotals->cogs_known ?? 0), 2);
        $lineCount = (int) ($lineTotals->line_count ?? 0);
        $zeroLines = (int) ($lineTotals->zero_lines ?? 0);
        $delivIncome = round((float) ($orderTotals->delivery_income ?? 0), 2);
        $courierCost = round((float) ($orderTotals->courier_cost ?? 0), 2);
        $packaging = round((float) ($orderTotals->packaging ?? 0), 2);

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
        [$from, $to] = $this->windowBounds($start, $end);

        $rows = DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            // MAX() keeps MySQL ONLY_FULL_GROUP_BY happy for the expression label.
            ->selectRaw("MAX(COALESCE(NULLIF(TRIM(placed_via), ''), '(unknown)')) as via")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total), 0) as gmv')
            ->groupByRaw("COALESCE(NULLIF(TRIM(placed_via), ''), '(unknown)')")
            ->orderByDesc('orders')
            ->get();

        $total = max(1, (int) $rows->sum('orders'));

        return $rows->map(fn ($row): array => [
            'via' => (string) $row->via,
            'orders' => (int) $row->orders,
            'gmv' => round((float) $row->gmv, 2),
            'share_pct' => round(((int) $row->orders) * 100 / $total, 1),
        ])->all();
    }

    /**
     * @return list<array{city: string, orders: int, gmv: float}>
     */
    private function geos(Carbon $start, Carbon $end): array
    {
        [$from, $to] = $this->windowBounds($start, $end);

        $rows = DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            // MAX() keeps MySQL ONLY_FULL_GROUP_BY happy for the expression label.
            ->selectRaw("MAX(COALESCE(NULLIF(TRIM(city), ''), '(blank)')) as city")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total), 0) as gmv')
            ->groupByRaw("COALESCE(NULLIF(TRIM(city), ''), '(blank)')")
            ->orderByDesc('orders')
            ->limit(8)
            ->get();

        return $rows->map(fn ($row): array => [
            'city' => (string) $row->city,
            'orders' => (int) $row->orders,
            'gmv' => round((float) $row->gmv, 2),
        ])->all();
    }

    /**
     * @return list<array{name: string, orders: int, revenue: float}>
     */
    private function categories(Carbon $start, Carbon $end): array
    {
        [$from, $to] = $this->windowBounds($start, $end);
        $qty = OrderEconomicsSql::greatest('(op.quantity - COALESCE(op.returned_quantity, 0))', '0');

        $rows = DB::table('order_products as op')
            ->join('orders', 'orders.id', '=', 'op.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('orders.status', 'delivered')
            ->whereNotNull('orders.created_at')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('c.id')
            ->orderByDesc(DB::raw("SUM(op.price * {$qty})"))
            ->limit(8)
            ->get([
                DB::raw('MAX(COALESCE(c.name, "(uncategorized)")) as name'),
                DB::raw('COUNT(DISTINCT op.order_id) as orders'),
                DB::raw("SUM(op.price * {$qty}) as revenue"),
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

        $monthExpr = DhakaSql::month('created_at');

        foreach ($this->monthlyAggregateRows($start, $end) as $row) {
            $month = (int) $row->month;

            if ($month < 1 || $month > 12) {
                continue;
            }

            $months[$month]['orders'] = (int) $row->orders;
            $months[$month]['gmv'] = (float) $row->gmv;
            $months[$month]['delivered'] = (int) $row->delivered;
            $months[$month]['collected'] = (float) $row->collected;
            $months[$month]['dispatched'] = (int) $row->dispatched;
        }

        [$priorFrom, $priorTo] = $this->windowBounds($priorStart, $priorEnd);
        $priorRows = DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$priorFrom, $priorTo])
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total), 0) as gmv')
            ->groupByRaw($monthExpr)
            ->get();

        foreach ($priorRows as $row) {
            $month = (int) $row->month;

            if ($month < 1 || $month > 12) {
                continue;
            }

            $months[$month]['prior_orders'] = (int) $row->orders;
            $months[$month]['prior_gmv'] = (float) $row->gmv;
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
     * @return Collection<int, object>
     */
    private function monthlyAggregateRows(Carbon $start, Carbon $end)
    {
        [$from, $to] = $this->windowBounds($start, $end);
        $monthExpr = DhakaSql::month('created_at');

        return DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total), 0) as gmv')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) as delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN collected_amount ELSE 0 END), 0) as collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END), 0) as dispatched")
            ->groupByRaw($monthExpr)
            ->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function windowBounds(Carbon $start, Carbon $end): array
    {
        return [
            $start->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
            $end->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
        ];
    }

    private function pctChange(float $prior, float $current): ?float
    {
        if ($prior == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(($current - $prior) * 100 / $prior, 1);
    }
}
