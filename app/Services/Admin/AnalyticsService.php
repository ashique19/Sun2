<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public const METRICS = ['revenue', 'direct', 'indirect', 'profit'];

    /**
     * Years that have placed or delivered orders.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = [];

        foreach ([
            ['status' => 'delivered', 'column' => 'actual_delivery_date'],
            ['status' => null, 'column' => 'placed_at'],
        ] as $source) {
            $query = DB::table('orders')->whereNotNull($source['column']);

            if ($source['status'] !== null) {
                $query->where('status', $source['status']);
            }

            $query->orderBy('id')
                ->select(['id', $source['column']])
                ->chunkById(1000, function ($rows) use ($source, &$years): void {
                    foreach ($rows as $row) {
                        $year = $this->safeDhakaYear($row->{$source['column']} ?? null);

                        if ($year !== null) {
                            $years[$year] = true;
                        }
                    }
                });
        }

        $list = array_keys($years);
        rsort($list, SORT_NUMERIC);

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
     * Year overview: months sized by collected revenue (delivered orders).
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
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfYear();

        $rows = Order::query()
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->whereBetween('actual_delivery_date', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->get(['id', 'collected_amount', 'actual_delivery_date']);

        $byMonth = [];

        for ($month = 1; $month <= 12; $month++) {
            $byMonth[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'revenue' => 0.0,
                'order_count' => 0,
            ];
        }

        foreach ($rows as $order) {
            $month = $this->safeDhakaMonth($order->getAttributes()['actual_delivery_date'] ?? $order->actual_delivery_date);

            if ($month === null) {
                continue;
            }

            $byMonth[$month]['revenue'] += (float) ($order->collected_amount ?? 0);
            $byMonth[$month]['order_count']++;
        }

        $months = array_values(array_map(function (array $row): array {
            $row['revenue'] = round($row['revenue'], 2);

            return $row;
        }, $byMonth));

        return [
            'year' => $year,
            'revenue' => round(array_sum(array_column($months, 'revenue')), 2),
            'order_count' => (int) array_sum(array_column($months, 'order_count')),
            'months' => $months,
        ];
    }

    /**
     * Monthly ordered vs delivered cohort by placement month (placed_at).
     * Delivered count/value are from orders placed that month that later reached delivered status
     * (not when the delivery date fell).
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
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfYear();

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

        $placed = Order::query()
            ->whereNotNull('placed_at')
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereBetween('placed_at', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->get(['id', 'total', 'placed_at', 'status', 'collected_amount']);

        foreach ($placed as $order) {
            $month = $this->safeDhakaMonth($order->getAttributes()['placed_at'] ?? $order->placed_at);

            if ($month === null) {
                continue;
            }

            $months[$month]['ordered_count']++;
            $months[$month]['ordered_value'] += (float) ($order->total ?? 0);

            if ($order->status !== 'delivered') {
                continue;
            }

            $months[$month]['delivered_count']++;
            $collected = (float) ($order->collected_amount ?? 0);
            $months[$month]['delivered_value'] += $collected > 0
                ? $collected
                : (float) ($order->total ?? 0);
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
     * Delivered line revenue by product category × month (actual_delivery_date).
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
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfYear();
        $palette = [
            '#1F4E79', '#C9A227', '#2F6F4E', '#C45C26', '#6B8F71',
            '#8B5E3C', '#3D6B8E', '#A04820', '#7A4E3A', '#4F7A5A',
            '#B8956A', '#8C8474',
        ];

        $lines = OrderProduct::query()
            ->with(['product:id,category_id', 'product.category:id,name', 'order:id,status,actual_delivery_date'])
            ->whereHas('order', function ($query) use ($start, $end) {
                $query->where('status', 'delivered')
                    ->whereNotNull('actual_delivery_date')
                    ->whereBetween('actual_delivery_date', [
                        $start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                    ]);
            })
            ->get(['id', 'order_id', 'product_id', 'line_total']);

        /** @var array<string, array<int, float>> $matrix categoryKey => [month => amount] */
        $matrix = [];
        $names = [];

        foreach ($lines as $line) {
            $order = $line->order;

            if (! $order?->actual_delivery_date) {
                continue;
            }

            $month = $this->safeDhakaMonth(
                $order->getAttributes()['actual_delivery_date'] ?? $order->actual_delivery_date
            );

            if ($month === null) {
                continue;
            }

            $category = $line->product?->category;
            $categoryId = $category?->id;
            $key = $categoryId !== null ? (string) $categoryId : 'none';
            $names[$key] = $category?->name ?: 'Uncategorized';
            $matrix[$key] ??= array_fill(1, 12, 0.0);
            $matrix[$key][$month] += (float) ($line->line_total ?? 0);
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
        $orders = $this->deliveredOrdersForMonth($year, $month);
        $totals = $this->sumOrderEconomics($orders, $year, $month);

        $profitLabel = $totals['profit'] >= 0 ? 'Profit' : 'Loss';
        $profitColor = $totals['profit'] >= 0 ? '#2F6F4E' : '#B42318';

        return [
            'year' => $year,
            'month' => $month,
            'label' => Carbon::create($year, $month, 1)->format('F Y'),
            'order_count' => $orders->count(),
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

        $orders = $this->deliveredOrdersForMonth($year, $month);
        $totals = $this->sumOrderEconomics($orders, $year, $month);

        $orderRows = $orders->map(function (Order $order): ?array {
            try {
                $line = $this->orderEconomics($order);
            } catch (\Throwable) {
                return null;
            }

            $deliveredRaw = $order->getAttributes()['actual_delivery_date'] ?? null;
            $deliveredLabel = null;

            if ($deliveredRaw && $this->safeDhakaYear($deliveredRaw) !== null) {
                try {
                    $deliveredLabel = Carbon::parse($deliveredRaw)->timezone('Asia/Dhaka')->format('d M Y');
                } catch (\Throwable) {
                    $deliveredLabel = null;
                }
            }

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'name' => $order->name,
                'delivered_at' => $deliveredLabel,
                'revenue' => $line['revenue'],
                'cogs' => $line['cogs'],
                'packaging' => $line['packaging'],
                'courier' => $line['courier'],
                'cod' => $line['cod'],
                'direct' => $line['direct'],
                'profit' => $line['profit'],
            ];
        })->filter()->values();

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
                'blurb' => 'Collected amount on delivered orders (by delivery date).',
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
     * @return Collection<int, Order>
     */
    private function deliveredOrdersForMonth(int $year, int $month): Collection
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfMonth();

        return Order::query()
            ->with([
                'items:id,order_id,quantity,returned_quantity,purchase_price,unit_cost',
                'courier:id,slug,cod_percentage',
                'adjustments:id,order_id,type,label,amount',
            ])
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->whereBetween('actual_delivery_date', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->orderBy('actual_delivery_date')
            ->orderBy('id')
            ->get([
                'id',
                'order_number',
                'name',
                'status',
                'subtotal',
                'delivery_charge',
                'charge',
                'discount',
                'total',
                'courier_charge',
                'packaging_cost',
                'collected_amount',
                'paid_amount',
                'due_amount',
                'cod_amount',
                'courier_id',
                'actual_delivery_date',
            ]);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array{
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
    private function sumOrderEconomics(Collection $orders, int $year, int $month): array
    {
        $revenue = 0.0;
        $cogs = 0.0;
        $packaging = 0.0;
        $courier = 0.0;
        $cod = 0.0;

        $bill = 0.0;
        $productPrice = 0.0;
        $customerDelivery = 0.0;
        $otherCharges = 0.0;
        $discounts = 0.0;
        $remittanceBase = 0.0;
        $courierReceivable = 0.0;
        $grossProfit = 0.0;

        foreach ($orders as $order) {
            try {
                $line = $this->orderEconomics($order);
            } catch (\Throwable) {
                continue;
            }

            $revenue += $line['revenue'];
            $cogs += $line['cogs'];
            $packaging += $line['packaging'];
            $courier += $line['courier'];
            $cod += $line['cod'];

            try {
                $money = $order->moneyTotals();
                $bill += $money->billToCustomer;
                $productPrice += $money->subtotal;
                $customerDelivery += $money->deliveryCharge;
                $otherCharges += $money->charges;
                $discounts += $money->discounts;
                $remittanceBase += $money->remittanceBase;
                $courierReceivable += $money->courierReceivable;
                $grossProfit += $money->grossProfit;
            } catch (\Throwable) {
                // Contribution still counts; money-flow rows skip this order.
            }
        }

        $direct = $cogs + $packaging + $courier + $cod;
        $indirect = $this->indirectForMonth($year, $month);
        $profit = $revenue - $direct - $indirect;

        return [
            'revenue' => round($revenue, 2),
            'direct' => round($direct, 2),
            'direct_breakdown' => [
                'cogs' => round($cogs, 2),
                'packaging' => round($packaging, 2),
                'courier' => round($courier, 2),
                'cod' => round($cod, 2),
            ],
            'indirect' => round($indirect, 2),
            'profit' => round($profit, 2),
            'money' => [
                'bill_to_customer' => round($bill, 2),
                'product_price' => round($productPrice, 2),
                'customer_delivery' => round($customerDelivery, 2),
                'other_charges' => round($otherCharges, 2),
                'discounts' => round($discounts, 2),
                'remittance_base' => round($remittanceBase, 2),
                'courier_charge' => round($courier, 2),
                'cod_charge' => round($cod, 2),
                'courier_receivable' => round($courierReceivable, 2),
                'cogs' => round($cogs, 2),
                'packaging' => round($packaging, 2),
                'gross_profit' => round($grossProfit, 2),
                'indirect' => round($indirect, 2),
                'net_after_indirect' => round($grossProfit - $indirect, 2),
            ],
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
