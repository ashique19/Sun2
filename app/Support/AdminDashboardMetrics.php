<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardMetrics
{
    public const DAILY_CACHE_KEY = 'admin.dashboard_daily_totals';

    public const DAILY_CACHE_TTL = 60;

    /**
     * Current and previous calendar months of daily order metrics.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     is_current: bool,
     *     days: list<array{
     *         date: string,
     *         label: string,
     *         order_qty: int,
     *         order_value: float,
     *         delivery_qty: int,
     *         delivery_value: float
     *     }>,
     *     totals: array{
     *         order_qty: int,
     *         order_value: float,
     *         delivery_qty: int,
     *         delivery_value: float
     *     }
     * }>
     */
    public static function dailyTotals(bool $fresh = false): array
    {
        $cacheKey = self::DAILY_CACHE_KEY.':months:'.now()->toDateString();

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::DAILY_CACHE_TTL, function () {
            return self::computeMonthlyGroupedTotals();
        });
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     is_current: bool,
     *     days: list<array{
     *         date: string,
     *         label: string,
     *         order_qty: int,
     *         order_value: float,
     *         delivery_qty: int,
     *         delivery_value: float
     *     }>,
     *     totals: array{
     *         order_qty: int,
     *         order_value: float,
     *         delivery_qty: int,
     *         delivery_value: float
     *     }
     * }>
     */
    private static function computeMonthlyGroupedTotals(): array
    {
        $today = now()->startOfDay();
        $currentMonthStart = $today->copy()->startOfMonth();
        $previousMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $currentMonthStart->copy()->subDay();

        $ordersByDay = Order::query()
            ->where('placed_at', '>=', $previousMonthStart)
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->selectRaw('DATE(placed_at) as day')
            ->selectRaw('COUNT(*) as order_qty')
            ->selectRaw('COALESCE(SUM(total), 0) as order_value')
            ->groupByRaw('DATE(placed_at)')
            ->get()
            ->keyBy('day');

        // Delivery Qty / Collected Value: among orders placed that day, those now delivered.
        $deliveredByDay = Order::query()
            ->where('status', 'delivered')
            ->where('placed_at', '>=', $previousMonthStart)
            ->selectRaw('DATE(placed_at) as day')
            ->selectRaw('COALESCE(SUM(collected_amount), 0) as delivery_value')
            ->groupByRaw('DATE(placed_at)')
            ->get()
            ->keyBy('day');

        $deliveredItemsByDay = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.status', 'delivered')
            ->where('orders.placed_at', '>=', $previousMonthStart)
            ->selectRaw('DATE(orders.placed_at) as day')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_products.quantity > COALESCE(order_products.returned_quantity, 0) THEN order_products.quantity - COALESCE(order_products.returned_quantity, 0) ELSE 0 END), 0) as delivery_qty')
            ->groupByRaw('DATE(orders.placed_at)')
            ->get()
            ->keyBy('day');

        $buildDays = function (Carbon $from, Carbon $to) use ($ordersByDay, $deliveredByDay, $deliveredItemsByDay): array {
            $days = [];
            $cursor = $to->copy()->startOfDay();

            while ($cursor->gte($from)) {
                $date = $cursor->toDateString();
                $orderRow = $ordersByDay->get($date);
                $deliveredRow = $deliveredByDay->get($date);
                $itemRow = $deliveredItemsByDay->get($date);

                $days[] = [
                    'date' => $date,
                    'label' => $cursor->format('M-d'),
                    'order_qty' => (int) ($orderRow->order_qty ?? 0),
                    'order_value' => (float) ($orderRow->order_value ?? 0),
                    'delivery_qty' => (int) ($itemRow->delivery_qty ?? 0),
                    'delivery_value' => (float) ($deliveredRow->delivery_value ?? 0),
                ];

                $cursor->subDay();
            }

            return $days;
        };

        $sumDays = function (array $days): array {
            return [
                'order_qty' => (int) array_sum(array_column($days, 'order_qty')),
                'order_value' => (float) array_sum(array_column($days, 'order_value')),
                'delivery_qty' => (int) array_sum(array_column($days, 'delivery_qty')),
                'delivery_value' => (float) array_sum(array_column($days, 'delivery_value')),
            ];
        };

        $currentDays = $buildDays($currentMonthStart, $today);
        $previousDays = $buildDays($previousMonthStart, $previousMonthEnd);

        return [
            [
                'key' => $currentMonthStart->format('Y-m'),
                'label' => 'Current month',
                'is_current' => true,
                'days' => $currentDays,
                'totals' => $sumDays($currentDays),
            ],
            [
                'key' => $previousMonthStart->format('Y-m'),
                'label' => 'Previous month',
                'is_current' => false,
                'days' => $previousDays,
                'totals' => $sumDays($previousDays),
            ],
        ];
    }
}
