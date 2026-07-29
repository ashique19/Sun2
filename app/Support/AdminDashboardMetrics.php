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
     * @return list<array{
     *     date: string,
     *     label: string,
     *     order_qty: int,
     *     order_value: float,
     *     delivery_qty: int,
     *     delivery_value: float
     * }>
     */
    public static function dailyTotals(int $days = 30, bool $fresh = false): array
    {
        $days = max(1, $days);
        $cacheKey = self::DAILY_CACHE_KEY.':'.$days.':'.now()->toDateString();

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::DAILY_CACHE_TTL, function () use ($days) {
            return self::computeDailyTotals($days);
        });
    }

    /**
     * @return list<array{
     *     date: string,
     *     label: string,
     *     order_qty: int,
     *     order_value: float,
     *     delivery_qty: int,
     *     delivery_value: float
     * }>
     */
    private static function computeDailyTotals(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $ordersByDay = Order::query()
            ->where('placed_at', '>=', $start)
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->selectRaw('DATE(placed_at) as day')
            ->selectRaw('COUNT(*) as order_qty')
            ->selectRaw('COALESCE(SUM(total), 0) as order_value')
            ->groupByRaw('DATE(placed_at)')
            ->get()
            ->keyBy('day');

        // Delivery Qty / Collected Value: delivered orders only, by actual delivery date.
        $deliveredByDay = Order::query()
            ->where('status', 'delivered')
            ->whereNotNull('actual_delivery_date')
            ->where('actual_delivery_date', '>=', $start)
            ->selectRaw('DATE(actual_delivery_date) as day')
            ->selectRaw('COALESCE(SUM(collected_amount), 0) as delivery_value')
            ->groupByRaw('DATE(actual_delivery_date)')
            ->get()
            ->keyBy('day');

        $deliveredItemsByDay = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.status', 'delivered')
            ->whereNotNull('orders.actual_delivery_date')
            ->where('orders.actual_delivery_date', '>=', $start)
            ->selectRaw('DATE(orders.actual_delivery_date) as day')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_products.quantity > COALESCE(order_products.returned_quantity, 0) THEN order_products.quantity - COALESCE(order_products.returned_quantity, 0) ELSE 0 END), 0) as delivery_qty')
            ->groupByRaw('DATE(orders.actual_delivery_date)')
            ->get()
            ->keyBy('day');

        $rows = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->subDays($offset)->toDateString();
            $orderRow = $ordersByDay->get($date);
            $deliveredRow = $deliveredByDay->get($date);
            $itemRow = $deliveredItemsByDay->get($date);

            $rows[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d M Y'),
                'order_qty' => (int) ($orderRow->order_qty ?? 0),
                'order_value' => (float) ($orderRow->order_value ?? 0),
                'delivery_qty' => (int) ($itemRow->delivery_qty ?? 0),
                'delivery_value' => (float) ($deliveredRow->delivery_value ?? 0),
            ];
        }

        return array_reverse($rows);
    }
}
