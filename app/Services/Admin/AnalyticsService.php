<?php

namespace App\Services\Admin;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AnalyticsService
{
    public const METRICS = ['revenue', 'direct', 'indirect', 'profit'];

    /**
     * Years that have at least one delivered order with an actual delivery date.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = Order::query()
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->get(['actual_delivery_date'])
            ->map(fn (Order $order) => (int) $order->actual_delivery_date->timezone('Asia/Dhaka')->year)
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
        $totals = $this->sumOrderEconomics($orders);

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
     * Detail rows for a metric within a month.
     *
     * @return array{summary: array<string, float|int|string>, orders: Collection<int, array<string, mixed>>}
     */
    public function metricDetail(int $year, int $month, string $metric): array
    {
        $metric = strtolower($metric);

        if (! in_array($metric, self::METRICS, true)) {
            abort(404);
        }

        $orders = $this->deliveredOrdersForMonth($year, $month);
        $totals = $this->sumOrderEconomics($orders);

        $rows = $orders->map(function (Order $order): array {
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
                'indirect' => $line['indirect'],
                'profit' => $line['profit'],
            ];
        })->values();

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
                'blurb' => 'Not tracked yet (ads, salaries, rent, etc.). Showing ৳0 until you add those costs.',
                'total' => $totals['indirect'],
            ],
            'profit' => [
                'title' => $totals['profit'] >= 0 ? 'Profit' : 'Loss',
                'blurb' => 'Revenue − direct cost − indirect cost.',
                'total' => $totals['profit'],
            ],
        };

        return [
            'summary' => $summary,
            'orders' => $rows,
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
    private function sumOrderEconomics(Collection $orders): array
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
        $indirect = 0.0;
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
     * @return array{
     *     revenue: float,
     *     cogs: float,
     *     packaging: float,
     *     courier: float,
     *     cod: float,
     *     direct: float,
     *     indirect: float,
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
        $indirect = 0.0;

        return [
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'packaging' => round($packaging, 2),
            'courier' => round($courier, 2),
            'cod' => round($cod, 2),
            'direct' => round($direct, 2),
            'indirect' => round($indirect, 2),
            'profit' => round($revenue - $direct - $indirect, 2),
        ];
    }
}
