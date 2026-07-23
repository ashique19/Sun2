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
     * Lifetime snapshot metrics for a single product.
     *
     * @return array{
     *     order_count: int,
     *     sales_volume: int,
     *     sales_value: float,
     *     delivered_volume: int,
     *     delivered_value: float,
     *     returned_volume: int,
     *     commission_earned: float,
     *     delivered_pct: float|null
     * }
     */
    public function productSummary(Product $product): array
    {
        $row = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->whereNotNull('orders.placed_at')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as sales_volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as sales_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.status = 'delivered' THEN order_products.quantity ELSE 0 END), 0) as delivered_volume")
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.status = 'delivered' THEN order_products.line_total ELSE 0 END), 0) as delivered_value")
            ->selectRaw('COALESCE(SUM(order_products.returned_quantity), 0) as returned_volume')
            ->selectRaw('COALESCE(SUM(order_products.commission_earned), 0) as commission_earned')
            ->first();

        $salesVolume = (int) ($row->sales_volume ?? 0);
        $deliveredVolume = (int) ($row->delivered_volume ?? 0);

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'sales_volume' => $salesVolume,
            'sales_value' => (float) ($row->sales_value ?? 0),
            'delivered_volume' => $deliveredVolume,
            'delivered_value' => (float) ($row->delivered_value ?? 0),
            'returned_volume' => (int) ($row->returned_volume ?? 0),
            'commission_earned' => (float) ($row->commission_earned ?? 0),
            'delivered_pct' => $salesVolume > 0
                ? round(($deliveredVolume / $salesVolume) * 100, 1)
                : null,
        ];
    }

    /**
     * Units/value by order placement channel for a product.
     *
     * @return list<array{placed_via: string, label: string, volume: int, value: float}>
     */
    public function productChannelBreakdown(Product $product): array
    {
        $rows = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->whereNotNull('orders.placed_at')
            ->selectRaw("COALESCE(orders.placed_via, 'storefront') as placed_via")
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as value')
            ->groupByRaw("COALESCE(orders.placed_via, 'storefront')")
            ->orderByDesc('volume')
            ->get();

        $channelLabels = [
            'storefront' => 'Customer',
            'admin' => 'Admin',
            'reseller' => 'Reseller',
        ];

        return $rows->map(fn ($row) => [
            'placed_via' => (string) $row->placed_via,
            'label' => $channelLabels[(string) $row->placed_via] ?? ucfirst((string) $row->placed_via),
            'volume' => (int) $row->volume,
            'value' => (float) $row->value,
        ])->all();
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
        [$yearExpr, $monthExpr] = $this->yearMonthExpressions('orders.placed_at');

        $sales = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->whereNotNull('orders.placed_at')
            ->where('orders.placed_at', '>=', $start->toDateTimeString())
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as sales_volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as sales_value')
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get()
            ->keyBy(fn ($row) => ((int) $row->year).'-'.((int) $row->month));

        $delivered = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('order_products.product_id', $product->id)
            ->where('orders.status', 'delivered')
            ->whereNotNull('orders.placed_at')
            ->where('orders.placed_at', '>=', $start->toDateTimeString())
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as delivered_volume')
            ->selectRaw('COALESCE(SUM(order_products.line_total), 0) as delivered_value')
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get()
            ->keyBy(fn ($row) => ((int) $row->year).'-'.((int) $row->month));

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

    /**
     * @return array{0: string, 1: string}
     */
    private function yearMonthExpressions(string $column): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return [
                "CAST(strftime('%Y', {$column}) AS INTEGER)",
                "CAST(strftime('%m', {$column}) AS INTEGER)",
            ];
        }

        return [
            "YEAR({$column})",
            "MONTH({$column})",
        ];
    }
}
