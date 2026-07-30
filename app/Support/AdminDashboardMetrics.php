<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class AdminDashboardMetrics
{
    public const DAILY_CACHE_KEY = 'admin.dashboard_daily_totals.v3';

    public const DAILY_CACHE_TTL = 60;

    public const RANGE_LAST7 = 'last7';

    public const RANGE_CURRENT = 'current';

    public const RANGE_PREVIOUS = 'previous';

    /**
     * Month tiles + last-7-days / month day breakdowns for the dashboard.
     *
     * Placement-day cohort:
     * - OQ / OV: orders placed that Asia/Dhaka calendar day
     * - DQ: how many of those orders later reached status=delivered
     *   (actual delivery may be on a later date — still counted on the placement day)
     * - CV: money received on those delivered orders (paid_amount, else collected, else total)
     *
     * @return array{
     *     months: list<array{
     *         key: string,
     *         range: string,
     *         label: string,
     *         is_current: bool,
     *         days: list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>,
     *         totals: array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}
     *     }>,
     *     last7: array{
     *         key: string,
     *         range: string,
     *         label: string,
     *         days: list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>,
     *         totals: array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}
     *     }
     * }
     */
    public static function orderActivity(bool $fresh = false): array
    {
        $cacheKey = self::DAILY_CACHE_KEY.':activity:'.now('Asia/Dhaka')->toDateString();

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::DAILY_CACHE_TTL, function () {
            return self::computeOrderActivity();
        });
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     is_current: bool,
     *     days: list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>,
     *     totals: array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}
     * }>
     */
    public static function dailyTotals(bool $fresh = false): array
    {
        $activity = self::orderActivity($fresh);

        return array_map(static function (array $month): array {
            return [
                'key' => $month['key'],
                'label' => $month['label'],
                'is_current' => $month['is_current'],
                'days' => $month['days'],
                'totals' => $month['totals'],
            ];
        }, $activity['months']);
    }

    /**
     * Collected / paid value for a delivered order in the placement cohort.
     */
    public static function deliveredOrderValue(Order $order): float
    {
        $paid = (float) ($order->paid_amount ?? 0);
        if ($paid > 0) {
            return $paid;
        }

        $collected = (float) ($order->collected_amount ?? 0);
        if ($collected > 0) {
            return $collected;
        }

        return (float) ($order->total ?? 0);
    }

    /**
     * @return array{
     *     months: list<array{
     *         key: string,
     *         range: string,
     *         label: string,
     *         is_current: bool,
     *         days: list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>,
     *         totals: array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}
     *     }>,
     *     last7: array{
     *         key: string,
     *         range: string,
     *         label: string,
     *         days: list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>,
     *         totals: array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}
     *     }
     * }
     */
    private static function computeOrderActivity(): array
    {
        $today = now('Asia/Dhaka')->startOfDay();
        $currentMonthStart = $today->copy()->startOfMonth();
        $previousMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $currentMonthStart->copy()->subDay();
        $last7Start = $today->copy()->subDays(6);
        $queryFrom = $previousMonthStart->lt($last7Start) ? $previousMonthStart : $last7Start;

        /** @var array<string, array{order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}> $byDay */
        $byDay = [];

        $orders = Order::query()
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', $queryFrom->copy()->timezone('UTC'))
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->get(['id', 'status', 'total', 'paid_amount', 'collected_amount', 'placed_at']);

        foreach ($orders as $order) {
            $date = $order->placed_at->timezone('Asia/Dhaka')->toDateString();
            $byDay[$date] ??= [
                'order_qty' => 0,
                'order_value' => 0.0,
                'delivery_qty' => 0,
                'delivery_value' => 0.0,
            ];

            // OQ: one per placed order (not line-item pieces).
            $byDay[$date]['order_qty']++;
            $byDay[$date]['order_value'] += (float) ($order->total ?? 0);

            if ($order->status !== 'delivered') {
                continue;
            }

            // DQ: still on the placement day when delivery happened later.
            $byDay[$date]['delivery_qty']++;
            $byDay[$date]['delivery_value'] += self::deliveredOrderValue($order);
        }

        $buildDays = function (Carbon $from, Carbon $to) use ($byDay): array {
            $days = [];
            $cursor = $to->copy()->startOfDay();

            while ($cursor->gte($from)) {
                $date = $cursor->toDateString();
                $row = $byDay[$date] ?? null;

                $days[] = [
                    'date' => $date,
                    'label' => $cursor->format('M-d'),
                    'order_qty' => (int) ($row['order_qty'] ?? 0),
                    'order_value' => round((float) ($row['order_value'] ?? 0), 2),
                    'delivery_qty' => (int) ($row['delivery_qty'] ?? 0),
                    'delivery_value' => round((float) ($row['delivery_value'] ?? 0), 2),
                ];

                $cursor->subDay();
            }

            return $days;
        };

        $sumDays = function (array $days): array {
            return [
                'order_qty' => (int) array_sum(array_column($days, 'order_qty')),
                'order_value' => round((float) array_sum(array_column($days, 'order_value')), 2),
                'delivery_qty' => (int) array_sum(array_column($days, 'delivery_qty')),
                'delivery_value' => round((float) array_sum(array_column($days, 'delivery_value')), 2),
            ];
        };

        $currentDays = $buildDays($currentMonthStart, $today);
        $previousDays = $buildDays($previousMonthStart, $previousMonthEnd);
        $last7Days = $buildDays($last7Start, $today);

        return [
            'months' => [
                [
                    'key' => $currentMonthStart->format('Y-m'),
                    'range' => self::RANGE_CURRENT,
                    'label' => 'This month',
                    'is_current' => true,
                    'days' => $currentDays,
                    'totals' => $sumDays($currentDays),
                ],
                [
                    'key' => $previousMonthStart->format('Y-m'),
                    'range' => self::RANGE_PREVIOUS,
                    'label' => 'Last month',
                    'is_current' => false,
                    'days' => $previousDays,
                    'totals' => $sumDays($previousDays),
                ],
            ],
            'last7' => [
                'key' => 'last7',
                'range' => self::RANGE_LAST7,
                'label' => 'Last 7 days',
                'days' => $last7Days,
                'totals' => $sumDays($last7Days),
            ],
        ];
    }
}
