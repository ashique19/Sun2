<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    /**
     * @return list<int>
     */
    public function availableYears(): array
    {
        $minYear = Order::query()
            ->whereNotNull('placed_at')
            ->min(DB::raw('YEAR(placed_at)'));

        $maxYear = (int) now('Asia/Dhaka')->year;
        $start = $minYear ? (int) $minYear : $maxYear;

        return range($maxYear, $start);
    }

    /**
     * @return list<array{
     *     month: int,
     *     label: string,
     *     sales_volume: int,
     *     sales_value: float,
     *     delivered_volume: int,
     *     delivered_value: float,
     *     delivered_pct: float|null
     * }>
     */
    public function salesByMonth(int $year): array
    {
        $sales = Order::query()
            ->whereNotNull('placed_at')
            ->whereYear('placed_at', $year)
            ->selectRaw('MONTH(placed_at) as month')
            ->selectRaw('COUNT(*) as sales_volume')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_value')
            ->groupByRaw('MONTH(placed_at)')
            ->get()
            ->keyBy('month');

        $delivered = Order::query()
            ->whereNotNull('placed_at')
            ->whereYear('placed_at', $year)
            ->where('status', 'delivered')
            ->selectRaw('MONTH(placed_at) as month')
            ->selectRaw('COUNT(*) as delivered_volume')
            ->selectRaw('COALESCE(SUM(total), 0) as delivered_value')
            ->groupByRaw('MONTH(placed_at)')
            ->get()
            ->keyBy('month');

        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $salesVolume = (int) ($sales->get($month)->sales_volume ?? 0);
            $salesValue = (float) ($sales->get($month)->sales_value ?? 0);
            $deliveredVolume = (int) ($delivered->get($month)->delivered_volume ?? 0);
            $deliveredValue = (float) ($delivered->get($month)->delivered_value ?? 0);

            $rows[] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F'),
                'sales_volume' => $salesVolume,
                'sales_value' => $salesValue,
                'delivered_volume' => $deliveredVolume,
                'delivered_value' => $deliveredValue,
                'delivered_pct' => $salesVolume > 0
                    ? round(($deliveredVolume / $salesVolume) * 100, 1)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Last 48 calendar months (newest first), product sale + delivery by placed month.
     *
     * @return list<array{
     *     year: int,
     *     month: int,
     *     label: string,
     *     sales_volume: int,
     *     sales_value: float,
     *     delivered_volume: int,
     *     delivered_value: float,
     *     delivered_pct: float|null
     * }>
     */
    public function productPerformance(Product $product, int $months = 48): array
    {
        $months = max(1, $months);
        $end = now('Asia/Dhaka')->startOfMonth();
        $start = $end->copy()->subMonths($months - 1);

        $sales = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->whereNotNull('orders.placed_at')
            ->where('orders.placed_at', '>=', $start->toDateTimeString())
            ->selectRaw('YEAR(orders.placed_at) as year')
            ->selectRaw('MONTH(orders.placed_at) as month')
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as sales_volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as sales_value')
            ->groupByRaw('YEAR(orders.placed_at), MONTH(orders.placed_at)')
            ->get()
            ->keyBy(fn ($row) => $row->year.'-'.$row->month);

        $delivered = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->where('orders.status', 'delivered')
            ->whereNotNull('orders.placed_at')
            ->where('orders.placed_at', '>=', $start->toDateTimeString())
            ->selectRaw('YEAR(orders.placed_at) as year')
            ->selectRaw('MONTH(orders.placed_at) as month')
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as delivered_volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as delivered_value')
            ->groupByRaw('YEAR(orders.placed_at), MONTH(orders.placed_at)')
            ->get()
            ->keyBy(fn ($row) => $row->year.'-'.$row->month);

        $rows = [];
        $cursor = $end->copy();

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->year.'-'.$cursor->month;
            $salesVolume = (int) ($sales->get($key)->sales_volume ?? 0);
            $salesValue = (float) ($sales->get($key)->sales_value ?? 0);
            $deliveredVolume = (int) ($delivered->get($key)->delivered_volume ?? 0);
            $deliveredValue = (float) ($delivered->get($key)->delivered_value ?? 0);

            $rows[] = [
                'year' => (int) $cursor->year,
                'month' => (int) $cursor->month,
                'label' => $cursor->format('M Y'),
                'sales_volume' => $salesVolume,
                'sales_value' => $salesValue,
                'delivered_volume' => $deliveredVolume,
                'delivered_value' => $deliveredValue,
                'delivered_pct' => $salesVolume > 0
                    ? round(($deliveredVolume / $salesVolume) * 100, 1)
                    : null,
            ];

            $cursor->subMonth();
        }

        return $rows;
    }
}
