<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardMetrics
{
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
    public static function dailyTotals(int $days = 30): array
    {
        $days = max(1, $days);
        $start = now()->subDays($days - 1)->startOfDay();

        $ordersByDay = Order::query()
            ->where('placed_at', '>=', $start)
            ->selectRaw('DATE(placed_at) as day')
            ->selectRaw('COUNT(*) as order_qty')
            ->selectRaw('COALESCE(SUM(total), 0) as order_value')
            ->selectRaw('COALESCE(SUM(delivery_charge), 0) as delivery_value')
            ->groupByRaw('DATE(placed_at)')
            ->get()
            ->keyBy('day');

        $itemsByDay = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.placed_at', '>=', $start)
            ->selectRaw('DATE(orders.placed_at) as day')
            ->selectRaw('COALESCE(SUM(order_products.quantity), 0) as delivery_qty')
            ->groupByRaw('DATE(orders.placed_at)')
            ->get()
            ->keyBy('day');

        $rows = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->subDays($offset)->toDateString();
            $orderRow = $ordersByDay->get($date);
            $itemRow = $itemsByDay->get($date);

            $rows[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d M Y'),
                'order_qty' => (int) ($orderRow->order_qty ?? 0),
                'order_value' => (float) ($orderRow->order_value ?? 0),
                'delivery_qty' => (int) ($itemRow->delivery_qty ?? 0),
                'delivery_value' => (float) ($orderRow->delivery_value ?? 0),
            ];
        }

        return array_reverse($rows);
    }
}
