<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
        $deliveryYears = Order::query()
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->get(['actual_delivery_date'])
            ->map(fn (Order $order) => (int) $order->actual_delivery_date->timezone('Asia/Dhaka')->year);

        $placedYears = Order::query()
            ->whereNotNull('placed_at')
            ->get(['placed_at'])
            ->map(fn (Order $order) => (int) $order->placed_at->timezone('Asia/Dhaka')->year);

        $years = $deliveryYears
            ->merge($placedYears)
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
            $month = (int) $order->actual_delivery_date->timezone('Asia/Dhaka')->month;
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
     * Monthly ordered (by placed_at) vs delivered (by actual_delivery_date).
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
            ->whereBetween('placed_at', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->get(['id', 'total', 'placed_at']);

        foreach ($placed as $order) {
            $month = (int) $order->placed_at->timezone('Asia/Dhaka')->month;
            $months[$month]['ordered_count']++;
            $months[$month]['ordered_value'] += (float) ($order->total ?? 0);
        }

        $delivered = Order::query()
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->whereBetween('actual_delivery_date', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])
            ->get(['id', 'collected_amount', 'total', 'actual_delivery_date']);

        foreach ($delivered as $order) {
            $month = (int) $order->actual_delivery_date->timezone('Asia/Dhaka')->month;
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

        $orderRows = $orders->map(function (Order $order): array {
            $line = $this->orderEconomics($order);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'name' => $order->name,
                'delivered_at' => $order->actual_delivery_date?->timezone('Asia/Dhaka')->format('d M Y'),
                'revenue' => $line['revenue'],
                'cogs' => $line['cogs'],
                'packaging' => $line['packaging'],
                'courier' => $line['courier'],
                'cod' => $line['cod'],
                'direct' => $line['direct'],
                'profit' => $line['profit'],
            ];
        })->values();

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
            ->with(['items:id,order_id,quantity,returned_quantity,purchase_price', 'courier:id,slug,cod_percentage'])
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
                'courier_charge',
                'packaging_cost',
                'collected_amount',
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
     *     profit: float
     * }
     */
    private function sumOrderEconomics(Collection $orders, int $year, int $month): array
    {
        $revenue = 0.0;
        $cogs = 0.0;
        $packaging = 0.0;
        $courier = 0.0;
        $cod = 0.0;

        foreach ($orders as $order) {
            $line = $this->orderEconomics($order);
            $revenue += $line['revenue'];
            $cogs += $line['cogs'];
            $packaging += $line['packaging'];
            $courier += $line['courier'];
            $cod += $line['cod'];
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
     *     profit: float
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

        return [
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'packaging' => round($packaging, 2),
            'courier' => round($courier, 2),
            'cod' => round($cod, 2),
            'direct' => round($direct, 2),
            'profit' => round($revenue - $direct, 2),
        ];
    }
}
