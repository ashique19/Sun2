<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\Order;
use App\Support\DhakaSql;
use App\Support\OrderEconomicsSql;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-year month series for analytics compare charts (last N calendar years).
 */
class AnalyticsYearCompareService
{
    public const YEAR_COUNT = 10;

    /** @var list<string> */
    public const METRICS = [
        'profit',
        'revenue',
        'ordered_count',
        'ordered_value',
        'delivered_count',
        'delivered_value',
        'category_revenue',
    ];

    /** @var array<string, array{label: string, hint: string, format: string}> */
    public const METRIC_META = [
        'profit' => [
            'label' => 'Profit / loss',
            'hint' => 'Collected − direct − expenses (delivered, by created month)',
            'format' => 'money',
        ],
        'revenue' => [
            'label' => 'Collected revenue',
            'hint' => 'Collected amount on delivered orders (by created month)',
            'format' => 'money',
        ],
        'ordered_count' => [
            'label' => 'Orders placed',
            'hint' => 'Non-draft orders created that month',
            'format' => 'number',
        ],
        'ordered_value' => [
            'label' => 'Order value',
            'hint' => 'Sum of order totals created that month',
            'format' => 'money',
        ],
        'delivered_count' => [
            'label' => 'Delivered count',
            'hint' => 'Orders created that month that are delivered',
            'format' => 'number',
        ],
        'delivered_value' => [
            'label' => 'Delivered value',
            'hint' => 'Collected (or total) for delivered orders created that month',
            'format' => 'money',
        ],
        'category_revenue' => [
            'label' => 'Category line revenue',
            'hint' => 'Delivered line totals (all categories) by order created month',
            'format' => 'money',
        ],
    ];

    /** @var list<string> */
    private const YEAR_COLORS = [
        '#8C8474', '#A67C52', '#8B5E3C', '#C9A227', '#6B8F71',
        '#2F6F4E', '#3D6B8E', '#1F4E79', '#C45C26', '#A04820',
    ];

    /**
     * @return array{
     *     metric: string,
     *     label: string,
     *     hint: string,
     *     format: string,
     *     years: list<int>,
     *     labels: list<string>,
     *     series: list<array{label: string, color: string, values: list<float>}>,
     *     year_totals: list<array{year: int, total: float, color: string}>
     * }
     */
    public function compare(string $metric, ?int $endYear = null): array
    {
        $metric = strtolower($metric);
        if (! in_array($metric, self::METRICS, true)) {
            $metric = 'profit';
        }

        $endYear = $endYear ?? (int) now('Asia/Dhaka')->year;
        $startYear = $endYear - self::YEAR_COUNT + 1;
        $years = range($startYear, $endYear);
        $meta = self::METRIC_META[$metric];

        $matrix = match ($metric) {
            'profit' => $this->profitByYearMonth($startYear, $endYear),
            'revenue' => $this->revenueByYearMonth($startYear, $endYear),
            'ordered_count', 'ordered_value', 'delivered_count', 'delivered_value' => $this->orderFlowByYearMonth($startYear, $endYear, $metric),
            'category_revenue' => $this->categoryRevenueByYearMonth($startYear, $endYear),
            default => [],
        };

        $labels = [];
        for ($month = 1; $month <= 12; $month++) {
            $labels[] = Carbon::create(2000, $month, 1)->format('M');
        }

        $series = [];
        $yearTotals = [];

        foreach ($years as $index => $year) {
            $values = [];
            for ($month = 1; $month <= 12; $month++) {
                $values[] = round((float) ($matrix[$year][$month] ?? 0), 2);
            }

            $color = self::YEAR_COLORS[$index % count(self::YEAR_COLORS)];
            $series[] = [
                'label' => (string) $year,
                'color' => $color,
                'values' => $values,
            ];
            $yearTotals[] = [
                'year' => $year,
                'total' => round(array_sum($values), 2),
                'color' => $color,
            ];
        }

        return [
            'metric' => $metric,
            'label' => $meta['label'],
            'hint' => $meta['hint'],
            'format' => $meta['format'],
            'years' => $years,
            'labels' => $labels,
            'series' => $series,
            'year_totals' => $yearTotals,
        ];
    }

    /**
     * @return array<int, array<int, float>> year => month => value
     */
    private function profitByYearMonth(int $startYear, int $endYear): array
    {
        [$from, $to] = $this->createdAtBounds($startYear, $endYear);
        $yearExpr = DhakaSql::year('orders.created_at');
        $monthExpr = DhakaSql::month('orders.created_at');

        $qty = OrderEconomicsSql::greatest(
            '(order_products.quantity - COALESCE(order_products.returned_quantity, 0))',
            '0',
        );
        $cogsPerOrder = DB::table('order_products')
            ->selectRaw('order_id')
            ->selectRaw("COALESCE(SUM({$qty} * COALESCE(order_products.unit_cost, order_products.purchase_price, 0)), 0) as cogs")
            ->groupBy('order_id');

        $steadfastBase = OrderEconomicsSql::greatest(
            '(COALESCE(orders.collected_amount, 0) - COALESCE(orders.delivery_charge, 0))',
            '0',
        );
        $codExpr = "CASE
            WHEN COALESCE(orders.collected_amount, 0) <= 0 THEN 0
            WHEN COALESCE(couriers.cod_percentage, 1) <= 0 THEN 0
            WHEN LOWER(COALESCE(couriers.slug, '')) = 'steadfast'
                THEN ROUND({$steadfastBase} * COALESCE(couriers.cod_percentage, 1) / 100.0, 2)
            ELSE ROUND(COALESCE(orders.collected_amount, 0) * COALESCE(couriers.cod_percentage, 1) / 100.0, 2)
        END";

        $rows = Order::query()
            ->leftJoinSub($cogsPerOrder, 'order_cogs', 'order_cogs.order_id', '=', 'orders.id')
            ->leftJoin('couriers', 'couriers.id', '=', 'orders.courier_id')
            ->where('orders.status', 'delivered')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(orders.collected_amount), 0) as revenue')
            ->selectRaw('COALESCE(SUM(COALESCE(order_cogs.cogs, 0)), 0) as cogs')
            ->selectRaw('COALESCE(SUM(orders.packaging_cost), 0) as packaging')
            ->selectRaw('COALESCE(SUM(orders.courier_charge), 0) as courier')
            ->selectRaw("COALESCE(SUM({$codExpr}), 0) as cod")
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get();

        $matrix = $this->emptyMatrix($startYear, $endYear);

        foreach ($rows as $row) {
            $year = (int) $row->year;
            $month = (int) $row->month;
            if ($year < $startYear || $year > $endYear || $month < 1 || $month > 12) {
                continue;
            }

            $direct = (float) $row->cogs + (float) $row->packaging + (float) $row->courier + (float) $row->cod;
            $matrix[$year][$month] = (float) $row->revenue - $direct;
        }

        $expenseYearExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', spent_on) AS INTEGER)"
            : 'YEAR(spent_on)';
        $expenseMonthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', spent_on) AS INTEGER)"
            : 'MONTH(spent_on)';

        $expenses = Expense::query()
            ->whereBetween('spent_on', [
                sprintf('%04d-01-01', $startYear),
                sprintf('%04d-12-31', $endYear),
            ])
            ->selectRaw("{$expenseYearExpr} as year")
            ->selectRaw("{$expenseMonthExpr} as month")
            ->selectRaw('COALESCE(SUM(amount), 0) as indirect')
            ->groupByRaw("{$expenseYearExpr}, {$expenseMonthExpr}")
            ->get();

        foreach ($expenses as $row) {
            $year = (int) $row->year;
            $month = (int) $row->month;
            if (! isset($matrix[$year][$month])) {
                continue;
            }
            $matrix[$year][$month] -= (float) $row->indirect;
        }

        return $matrix;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function revenueByYearMonth(int $startYear, int $endYear): array
    {
        [$from, $to] = $this->createdAtBounds($startYear, $endYear);
        $yearExpr = DhakaSql::year('created_at');
        $monthExpr = DhakaSql::month('created_at');

        $rows = Order::query()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(collected_amount), 0) as value')
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get();

        return $this->matrixFromRows($rows, $startYear, $endYear);
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function orderFlowByYearMonth(int $startYear, int $endYear, string $metric): array
    {
        [$from, $to] = $this->createdAtBounds($startYear, $endYear);
        $yearExpr = DhakaSql::year('created_at');
        $monthExpr = DhakaSql::month('created_at');
        $deliveredValue = 'CASE
            WHEN COALESCE(collected_amount, 0) > 0 THEN collected_amount
            ELSE COALESCE(total, 0)
        END';

        $valueExpr = match ($metric) {
            'ordered_count' => 'COUNT(*)',
            'ordered_value' => 'COALESCE(SUM(total), 0)',
            'delivered_count' => "COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0)",
            'delivered_value' => "COALESCE(SUM(CASE WHEN status = 'delivered' THEN ({$deliveredValue}) ELSE 0 END), 0)",
            default => 'COUNT(*)',
        };

        $rows = Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereBetween('created_at', [$from, $to])
            ->where('created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw("{$valueExpr} as value")
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get();

        return $this->matrixFromRows($rows, $startYear, $endYear);
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function categoryRevenueByYearMonth(int $startYear, int $endYear): array
    {
        [$from, $to] = $this->createdAtBounds($startYear, $endYear);
        $yearExpr = DhakaSql::year('orders.created_at');
        $monthExpr = DhakaSql::month('orders.created_at');
        $keptValue = OrderEconomicsSql::keptValueExpression();

        $rows = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.status', 'delivered')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.created_at', 'not like', '0000-00-00%')
            ->selectRaw("{$yearExpr} as year")
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw("COALESCE(SUM({$keptValue}), 0) as value")
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get();

        return $this->matrixFromRows($rows, $startYear, $endYear);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array<int, float>>
     */
    private function matrixFromRows($rows, int $startYear, int $endYear): array
    {
        $matrix = $this->emptyMatrix($startYear, $endYear);

        foreach ($rows as $row) {
            $year = (int) $row->year;
            $month = (int) $row->month;
            if ($year < $startYear || $year > $endYear || $month < 1 || $month > 12) {
                continue;
            }
            $matrix[$year][$month] = (float) $row->value;
        }

        return $matrix;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function emptyMatrix(int $startYear, int $endYear): array
    {
        $matrix = [];
        for ($year = $startYear; $year <= $endYear; $year++) {
            $matrix[$year] = array_fill(1, 12, 0.0);
        }

        return $matrix;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createdAtBounds(int $startYear, int $endYear): array
    {
        $start = Carbon::create($startYear, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = Carbon::create($endYear, 12, 31, 23, 59, 59, 'Asia/Dhaka');

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }
}
