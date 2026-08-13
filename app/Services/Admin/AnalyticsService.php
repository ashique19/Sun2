<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\Order;
use App\Support\DhakaSql;
use App\Support\OrderEconomicsSql;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public const METRICS = ['revenue', 'direct', 'indirect', 'profit'];

    /**
     * Years that have non-draft orders (by created_at).
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $yearExpr = DhakaSql::year('created_at');

        $list = DB::table('orders')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('created_at')
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw("DISTINCT {$yearExpr} as year")
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->filter(fn (int $year) => $year > 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        $current = (int) now('Asia/Dhaka')->year;

        if ($list === []) {
            return [$current];
        }

        if (! in_array($current, $list, true)) {
            array_unshift($list, $current);
        }

        return $list;
    }

    /**
     * Parse a stored datetime into a Dhaka calendar year, or null when unreadable.
     * Bad legacy values (e.g. 0000-00-00) must not crash year analytics pages.
     */
    public function safeDhakaYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = $value instanceof Carbon
            ? $value->format('Y-m-d H:i:s')
            : trim((string) $value);

        if ($string === '' || str_starts_with($string, '0000-00-00')) {
            return null;
        }

        try {
            $carbon = $value instanceof Carbon
                ? $value->copy()
                : Carbon::parse($string);

            return (int) $carbon->timezone('Asia/Dhaka')->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safe Dhaka month (1–12) from a stored datetime, or null when unreadable.
     */
    public function safeDhakaMonth(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = $value instanceof Carbon
            ? $value->format('Y-m-d H:i:s')
            : trim((string) $value);

        if ($string === '' || str_starts_with($string, '0000-00-00')) {
            return null;
        }

        try {
            $carbon = $value instanceof Carbon
                ? $value->copy()
                : Carbon::parse($string);

            return (int) $carbon->timezone('Asia/Dhaka')->month;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Year overview: months sized by collected revenue (delivered orders, by created_at).
     *
     * @return array{
     *     year: int,
     *     revenue: float,
     *     order_count: int,
     *     months: list<array{month: int, label: string, revenue: float, order_count: int}>
     * }
     */
    public function yearOverview(int $year): array
    {
        [$start, $end] = $this->createdAtYearBounds($year);
        $monthExpr = DhakaSql::month('created_at');

        $aggregated = Order::query()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$start, $end])
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(collected_amount), 0) as revenue')
            ->selectRaw('COUNT(*) as order_count')
            ->groupByRaw($monthExpr)
            ->get()
            ->keyBy(fn ($row) => (int) $row->month);

        $byMonth = [];

        for ($month = 1; $month <= 12; $month++) {
            $row = $aggregated->get($month);
            $byMonth[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'order_count' => (int) ($row->order_count ?? 0),
            ];
        }

        $months = array_values($byMonth);

        return [
            'year' => $year,
            'revenue' => round(array_sum(array_column($months, 'revenue')), 2),
            'order_count' => (int) array_sum(array_column($months, 'order_count')),
            'months' => $months,
        ];
    }

    /**
     * Monthly ordered vs delivered cohort by order created_at month.
     * Delivered count/value are from orders created that month that later reached delivered status.
     *
     * @return array{
     *     year: int,
     *     months: list<array{
     *         month: int,
     *         label: string,
     *         ordered_count: int,
     *         ordered_value: float,
     *         delivered_count: int,
     *         delivered_value: float
     *     }>,
     *     totals: array{
     *         ordered_count: int,
     *         ordered_value: float,
     *         delivered_count: int,
     *         delivered_value: float
     *     }
     * }
     */
    public function orderedVsDeliveredByMonth(int $year): array
    {
        [$start, $end] = $this->createdAtYearBounds($year);

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'ordered_count' => 0,
                'ordered_value' => 0.0,
                'delivered_count' => 0,
                'delivered_value' => 0.0,
            ];
        }

        $monthExpr = DhakaSql::month('created_at');
        $deliveredValue = 'CASE
            WHEN COALESCE(collected_amount, 0) > 0 THEN collected_amount
            ELSE COALESCE(total, 0)
        END';

        $aggregated = Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereBetween('created_at', [$start, $end])
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COUNT(*) as ordered_count')
            ->selectRaw('COALESCE(SUM(total), 0) as ordered_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) as delivered_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN ({$deliveredValue}) ELSE 0 END), 0) as delivered_value")
            ->groupByRaw($monthExpr)
            ->get()
            ->keyBy(fn ($row) => (int) $row->month);

        foreach ($aggregated as $month => $row) {
            if ($month < 1 || $month > 12) {
                continue;
            }

            $months[$month]['ordered_count'] = (int) $row->ordered_count;
            $months[$month]['ordered_value'] = (float) $row->ordered_value;
            $months[$month]['delivered_count'] = (int) $row->delivered_count;
            $months[$month]['delivered_value'] = (float) $row->delivered_value;
        }

        $rows = array_values(array_map(function (array $row): array {
            $row['ordered_value'] = round($row['ordered_value'], 2);
            $row['delivered_value'] = round($row['delivered_value'], 2);

            return $row;
        }, $months));

        return [
            'year' => $year,
            'months' => $rows,
            'totals' => [
                'ordered_count' => (int) array_sum(array_column($rows, 'ordered_count')),
                'ordered_value' => round(array_sum(array_column($rows, 'ordered_value')), 2),
                'delivered_count' => (int) array_sum(array_column($rows, 'delivered_count')),
                'delivered_value' => round(array_sum(array_column($rows, 'delivered_value')), 2),
            ],
        ];
    }

    /**
     * Delivered line revenue by product category × month (order created_at).
     * Values are order-line `line_total` sums (not prorated collected amounts).
     *
     * @return array{
     *     year: int,
     *     months: list<string>,
     *     categories: list<array{id: int|null, name: string, color: string, values: list<float>, total: float}>,
     *     month_totals: list<float>,
     *     grand_total: float
     * }
     */
    public function revenueByCategoryByMonth(int $year): array
    {
        [$start, $end] = $this->createdAtYearBounds($year);
        $palette = [
            '#1F4E79', '#C9A227', '#2F6F4E', '#C45C26', '#6B8F71',
            '#8B5E3C', '#3D6B8E', '#A04820', '#7A4E3A', '#4F7A5A',
            '#B8956A', '#8C8474',
        ];

        $monthExpr = DhakaSql::month('orders.created_at');

        $aggregated = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_products.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.status', 'delivered')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('categories.id as category_id')
            ->selectRaw("COALESCE(NULLIF(categories.name, ''), 'Uncategorized') as name")
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as amount')
            ->groupByRaw("{$monthExpr}, categories.id, COALESCE(NULLIF(categories.name, ''), 'Uncategorized')")
            ->get();

        /** @var array<string, array<int, float>> $matrix categoryKey => [month => amount] */
        $matrix = [];
        $names = [];

        foreach ($aggregated as $row) {
            $month = (int) $row->month;

            if ($month < 1 || $month > 12) {
                continue;
            }

            $categoryId = $row->category_id !== null ? (int) $row->category_id : null;
            $key = $categoryId !== null ? (string) $categoryId : 'none';
            $names[$key] = (string) ($row->name ?: 'Uncategorized');
            $matrix[$key] ??= array_fill(1, 12, 0.0);
            $matrix[$key][$month] += (float) $row->amount;
        }

        uasort($matrix, function (array $a, array $b): int {
            return array_sum($b) <=> array_sum($a);
        });

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[] = Carbon::create($year, $month, 1)->format('M');
        }

        $categories = [];
        $colorIndex = 0;

        foreach ($matrix as $key => $byMonth) {
            $values = [];
            for ($month = 1; $month <= 12; $month++) {
                $values[] = round($byMonth[$month], 2);
            }

            $categories[] = [
                'id' => $key === 'none' ? null : (int) $key,
                'name' => $names[$key],
                'color' => $palette[$colorIndex % count($palette)],
                'values' => $values,
                'total' => round(array_sum($values), 2),
            ];
            $colorIndex++;
        }

        $monthTotals = [];
        for ($i = 0; $i < 12; $i++) {
            $sum = 0.0;
            foreach ($categories as $category) {
                $sum += $category['values'][$i];
            }
            $monthTotals[] = round($sum, 2);
        }

        return [
            'year' => $year,
            'months' => $months,
            'categories' => $categories,
            'month_totals' => $monthTotals,
            'grand_total' => round(array_sum($monthTotals), 2),
        ];
    }

    /**
     * Month P&L for delivered orders in Asia/Dhaka calendar month.
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     label: string,
     *     order_count: int,
     *     revenue: float,
     *     direct: float,
     *     direct_breakdown: array{cogs: float, packaging: float, courier: float, cod: float},
     *     indirect: float,
     *     profit: float,
     *     money: array{
     *         bill_to_customer: float,
     *         product_price: float,
     *         customer_delivery: float,
     *         other_charges: float,
     *         discounts: float,
     *         remittance_base: float,
     *         courier_charge: float,
     *         cod_charge: float,
     *         courier_receivable: float,
     *         cogs: float,
     *         packaging: float,
     *         gross_profit: float,
     *         indirect: float,
     *         net_after_indirect: float
     *     },
     *     segments: list<array{key: string, label: string, value: float, color: string}>
     * }
     */
    public function monthBreakdown(int $year, int $month): array
    {
        $totals = $this->monthEconomicsAggregate($year, $month);

        $profitLabel = $totals['profit'] >= 0 ? 'Profit' : 'Loss';
        $profitColor = $totals['profit'] >= 0 ? '#2F6F4E' : '#B42318';

        return [
            'year' => $year,
            'month' => $month,
            'label' => Carbon::create($year, $month, 1)->format('F Y'),
            'order_count' => $totals['order_count'],
            'revenue' => $totals['revenue'],
            'direct' => $totals['direct'],
            'direct_breakdown' => $totals['direct_breakdown'],
            'indirect' => $totals['indirect'],
            'profit' => $totals['profit'],
            'money' => $totals['money'],
            'segments' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'value' => $totals['revenue'], 'color' => '#1F4E79'],
                ['key' => 'direct', 'label' => 'Direct cost', 'value' => $totals['direct'], 'color' => '#C45C26'],
                ['key' => 'indirect', 'label' => 'Indirect cost', 'value' => $totals['indirect'], 'color' => '#8C8474'],
                ['key' => 'profit', 'label' => $profitLabel, 'value' => abs($totals['profit']), 'color' => $profitColor],
            ],
        ];
    }

    /**
     * Sum of expenses with spent_on in the given calendar month.
     */
    public function indirectForMonth(int $year, int $month): float
    {
        return round((float) Expense::query()->forMonth($year, $month)->sum('amount'), 2);
    }

    /**
     * Detail rows for a metric within a month.
     *
     * @return array{
     *     summary: array<string, float|int|string>,
     *     orders: Collection<int, array<string, mixed>>,
     *     expenses: Collection<int, array<string, mixed>>,
     *     label: string
     * }
     */
    public function metricDetail(int $year, int $month, string $metric): array
    {
        $metric = strtolower($metric);

        if (! in_array($metric, self::METRICS, true)) {
            abort(404);
        }

        $totals = $this->monthEconomicsAggregate($year, $month);
        $orderRows = $this->deliveredOrderDetailRows($year, $month);

        $expenseRows = Expense::query()
            ->forMonth($year, $month)
            ->orderBy('spent_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'title' => $expense->title,
                'category' => $expense->categoryLabel(),
                'kind' => $expense->kindLabel(),
                'spent_on' => $expense->spent_on?->format('d M Y'),
                'amount' => (float) $expense->amount,
                'notes' => $expense->notes,
            ])
            ->values();

        $summary = match ($metric) {
            'revenue' => [
                'title' => 'Revenue',
                'blurb' => 'Collected amount on delivered orders (by order created date).',
                'total' => $totals['revenue'],
            ],
            'direct' => [
                'title' => 'Direct cost',
                'blurb' => 'COGS + packaging + courier charge + COD fee.',
                'total' => $totals['direct'],
                'cogs' => $totals['direct_breakdown']['cogs'],
                'packaging' => $totals['direct_breakdown']['packaging'],
                'courier' => $totals['direct_breakdown']['courier'],
                'cod' => $totals['direct_breakdown']['cod'],
            ],
            'indirect' => [
                'title' => 'Indirect cost',
                'blurb' => 'Business expenses (salary, rent, ads…) recorded for this month.',
                'total' => $totals['indirect'],
            ],
            'profit' => [
                'title' => $totals['profit'] >= 0 ? 'Profit' : 'Loss',
                'blurb' => 'Revenue − direct cost − indirect expenses. Order rows show contribution before indirect.',
                'total' => $totals['profit'],
                'indirect' => $totals['indirect'],
            ],
        };

        return [
            'summary' => $summary,
            'orders' => $orderRows,
            'expenses' => $expenseRows,
            'label' => Carbon::create($year, $month, 1)->format('F Y'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function deliveredOrderDetailRows(int $year, int $month): Collection
    {
        [$start, $end] = $this->createdAtMonthBounds($year, $month);
        $cogsExpr = OrderEconomicsSql::cogsExpression();
        $codExpr = OrderEconomicsSql::codExpression();
        $rows = collect();

        Order::query()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$start, $end])
            ->where('created_at', 'not like', '0000-00-00%')
            ->orderBy('id')
            ->select([
                'id',
                'order_number',
                'name',
                'collected_amount',
                'packaging_cost',
                'courier_charge',
                'created_at',
            ])
            ->selectRaw("({$cogsExpr}) as economics_cogs")
            ->selectRaw("({$codExpr}) as economics_cod")
            ->chunkById(200, function ($chunk) use (&$rows): void {
                foreach ($chunk as $order) {
                    $revenue = round((float) ($order->collected_amount ?? 0), 2);
                    $cogs = round((float) ($order->economics_cogs ?? 0), 2);
                    $packaging = round((float) ($order->packaging_cost ?? 0), 2);
                    $courier = round((float) ($order->courier_charge ?? 0), 2);
                    $cod = round((float) ($order->economics_cod ?? 0), 2);
                    $direct = round($cogs + $packaging + $courier + $cod, 2);
                    $profit = round($revenue - $direct, 2);

                    $createdRaw = $order->getAttributes()['created_at'] ?? null;
                    $createdLabel = null;

                    if ($createdRaw && $this->safeDhakaYear($createdRaw) !== null) {
                        try {
                            $createdLabel = Carbon::parse($createdRaw)->timezone('Asia/Dhaka')->format('d M Y');
                        } catch (\Throwable) {
                            $createdLabel = null;
                        }
                    }

                    $rows->push([
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'name' => $order->name,
                        'created_at' => $createdLabel,
                        'revenue' => $revenue,
                        'cogs' => $cogs,
                        'packaging' => $packaging,
                        'courier' => $courier,
                        'cod' => $cod,
                        'direct' => $direct,
                        'profit' => $profit,
                    ]);
                }
            });

        return $rows->values();
    }

    /**
     * @return array{
     *     order_count: int,
     *     revenue: float,
     *     direct: float,
     *     direct_breakdown: array{cogs: float, packaging: float, courier: float, cod: float},
     *     indirect: float,
     *     profit: float,
     *     money: array{
     *         bill_to_customer: float,
     *         product_price: float,
     *         customer_delivery: float,
     *         other_charges: float,
     *         discounts: float,
     *         remittance_base: float,
     *         courier_charge: float,
     *         cod_charge: float,
     *         courier_receivable: float,
     *         cogs: float,
     *         packaging: float,
     *         gross_profit: float,
     *         indirect: float,
     *         net_after_indirect: float
     *     }
     * }
     */
    private function monthEconomicsAggregate(int $year, int $month): array
    {
        [$start, $end] = $this->createdAtMonthBounds($year, $month);
        $cogsExpr = OrderEconomicsSql::cogsExpression();
        $codExpr = OrderEconomicsSql::codExpression();
        $remittanceExpr = OrderEconomicsSql::remittanceBaseExpression();

        $row = Order::query()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$start, $end])
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(collected_amount), 0) as revenue')
            ->selectRaw("COALESCE(SUM({$cogsExpr}), 0) as cogs")
            ->selectRaw('COALESCE(SUM(packaging_cost), 0) as packaging')
            ->selectRaw('COALESCE(SUM(courier_charge), 0) as courier')
            ->selectRaw("COALESCE(SUM({$codExpr}), 0) as cod")
            ->selectRaw('COALESCE(SUM(total), 0) as bill_to_customer')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as product_price')
            ->selectRaw('COALESCE(SUM(delivery_charge), 0) as customer_delivery')
            ->selectRaw('COALESCE(SUM(charge), 0) as other_charges')
            ->selectRaw('COALESCE(SUM(discount), 0) as discounts')
            ->selectRaw("COALESCE(SUM({$remittanceExpr}), 0) as remittance_base")
            ->first();

        $revenue = round((float) ($row->revenue ?? 0), 2);
        $cogs = round((float) ($row->cogs ?? 0), 2);
        $packaging = round((float) ($row->packaging ?? 0), 2);
        $courier = round((float) ($row->courier ?? 0), 2);
        $cod = round((float) ($row->cod ?? 0), 2);
        $direct = round($cogs + $packaging + $courier + $cod, 2);
        $indirect = $this->indirectForMonth($year, $month);
        $profit = round($revenue - $direct - $indirect, 2);
        $remittanceBase = round((float) ($row->remittance_base ?? 0), 2);
        $courierReceivable = round($remittanceBase - $courier - $cod, 2);
        $grossProfit = round($courierReceivable - $cogs - $packaging, 2);

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'revenue' => $revenue,
            'direct' => $direct,
            'direct_breakdown' => [
                'cogs' => $cogs,
                'packaging' => $packaging,
                'courier' => $courier,
                'cod' => $cod,
            ],
            'indirect' => $indirect,
            'profit' => $profit,
            'money' => [
                'bill_to_customer' => round((float) ($row->bill_to_customer ?? 0), 2),
                'product_price' => round((float) ($row->product_price ?? 0), 2),
                'customer_delivery' => round((float) ($row->customer_delivery ?? 0), 2),
                'other_charges' => round((float) ($row->other_charges ?? 0), 2),
                'discounts' => round((float) ($row->discounts ?? 0), 2),
                'remittance_base' => $remittanceBase,
                'courier_charge' => $courier,
                'cod_charge' => $cod,
                'courier_receivable' => $courierReceivable,
                'cogs' => $cogs,
                'packaging' => $packaging,
                'gross_profit' => $grossProfit,
                'indirect' => $indirect,
                'net_after_indirect' => round($grossProfit - $indirect, 2),
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} Inclusive created_at bounds for a Dhaka calendar year.
     */
    private function createdAtYearBounds(int $year): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfYear();

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{0: string, 1: string} Inclusive created_at bounds for a Dhaka calendar month.
     */
    private function createdAtMonthBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfMonth();

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Per-order contribution (before business-level indirect expenses).
     *
     * @return array{
     *     revenue: float,
     *     cogs: float,
     *     packaging: float,
     *     courier: float,
     *     cod: float,
     *     direct: float,
     *     profit: float,
     *     profit_pct: float|null
     * }
     */
    public function orderContribution(Order $order): array
    {
        return $this->orderEconomics($order);
    }

    /**
     * Per-order contribution (before business-level indirect expenses).
     *
     * @return array{
     *     revenue: float,
     *     cogs: float,
     *     packaging: float,
     *     courier: float,
     *     cod: float,
     *     direct: float,
     *     profit: float,
     *     profit_pct: float|null
     * }
     */
    private function orderEconomics(Order $order): array
    {
        $revenue = (float) ($order->collected_amount ?? 0);
        $cogs = $order->cogs();
        $packaging = (float) ($order->packaging_cost ?? 0);
        $courier = (float) ($order->courier_charge ?? 0);
        $cod = $order->codCharge();
        $direct = $cogs + $packaging + $courier + $cod;
        $profit = $revenue - $direct;

        return [
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'packaging' => round($packaging, 2),
            'courier' => round($courier, 2),
            'cod' => round($cod, 2),
            'direct' => round($direct, 2),
            'profit' => round($profit, 2),
            'profit_pct' => $revenue > 0
                ? round(($profit / $revenue) * 100, 1)
                : null,
        ];
    }
}
