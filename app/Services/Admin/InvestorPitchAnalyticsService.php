<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live investor-pitch metrics from current order/product data (Asia/Dhaka).
 */
class InvestorPitchAnalyticsService
{
    /**
     * @return array{
     *     as_of: string,
     *     window: array{start: string, end: string, label: string},
     *     prior_window: array{start: string, end: string, label: string},
     *     traction: array<string, mixed>,
     *     prior: array<string, mixed>,
     *     growth: array<string, mixed>,
     *     unit_economics: array<string, mixed>,
     *     channels: list<array{via: string, orders: int, gmv: float, share_pct: float}>,
     *     geos: list<array{city: string, orders: int, gmv: float}>,
     *     categories: list<array{name: string, orders: int, revenue: float}>,
     *     monthly: list<array{ym: string, label: string, orders: int, gmv: float, delivered: int, collected: float, dispatched: int}>,
     *     caveats: list<string>
     * }
     */
    public function deck(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now('Asia/Dhaka'))->copy()->timezone('Asia/Dhaka');
        $ltmStart = $asOf->copy()->subYear()->startOfDay();
        $priorEnd = $ltmStart->copy()->subSecond();
        $priorStart = $priorEnd->copy()->subYear()->addSecond()->startOfDay();

        $traction = $this->windowMetrics($ltmStart, $asOf);
        $prior = $this->windowMetrics($priorStart, $priorEnd);

        return [
            'as_of' => $asOf->format('Y-m-d H:i T'),
            'window' => [
                'start' => $ltmStart->toDateString(),
                'end' => $asOf->toDateString(),
                'label' => 'Last 12 months',
            ],
            'prior_window' => [
                'start' => $priorStart->toDateString(),
                'end' => $priorEnd->toDateString(),
                'label' => 'Prior 12 months',
            ],
            'traction' => $traction,
            'prior' => $prior,
            'growth' => $this->growth($traction, $prior),
            'unit_economics' => $this->unitEconomics($ltmStart, $asOf, $traction),
            'channels' => $this->channels($ltmStart, $asOf),
            'geos' => $this->geos($ltmStart, $asOf),
            'categories' => $this->categories($ltmStart, $asOf),
            'monthly' => $this->monthly($ltmStart, $asOf),
            'caveats' => [
                'Merchandise gross margin uses lines with purchase_price > 0; zero-cost lines are excluded from GM%.',
                'Courier cost uses snapshotted courier_charge (estimated or confirmed); historical gaps remain where fee was never stored.',
                'Contribution is before ads, salaries, rent, and other opex not fully captured in expenses.',
                'Unsettled dispatched orders are excluded from delivered/collected until settlement is recorded.',
            ],
        ];
    }

    /**
     * @return array{
     *     orders: int,
     *     gmv_placed: float,
     *     delivered: int,
     *     gmv_delivered: float,
     *     collected: float,
     *     collection_pct: float,
     *     returned: int,
     *     return_pct: float,
     *     dispatched: int,
     *     unsettled_gmv: float,
     *     aov: float,
     *     unique_buyers: int
     * }
     */
    public function windowMetrics(Carbon $start, Carbon $end): array
    {
        $orders = $this->ordersInWindow($start, $end);

        $ordersCount = $orders->count();
        $gmvPlaced = round((float) $orders->sum(fn (Order $o) => (float) $o->total), 2);
        $delivered = $orders->where('status', 'delivered');
        $deliveredCount = $delivered->count();
        $gmvDelivered = round((float) $delivered->sum(fn (Order $o) => (float) $o->total), 2);
        $collected = round((float) $delivered->sum(fn (Order $o) => (float) $o->collected_amount), 2);
        $returned = $orders->where('status', 'returned')->count();
        $dispatched = $orders->where('status', 'dispatched');

        return [
            'orders' => $ordersCount,
            'gmv_placed' => $gmvPlaced,
            'delivered' => $deliveredCount,
            'gmv_delivered' => $gmvDelivered,
            'collected' => $collected,
            'collection_pct' => $gmvDelivered > 0
                ? round($collected * 100 / $gmvDelivered, 1)
                : 0.0,
            'returned' => $returned,
            'return_pct' => $ordersCount > 0
                ? round($returned * 100 / $ordersCount, 1)
                : 0.0,
            'dispatched' => $dispatched->count(),
            'unsettled_gmv' => round((float) $dispatched->sum(fn (Order $o) => (float) $o->total), 2),
            'aov' => $ordersCount > 0 ? round($gmvPlaced / $ordersCount, 0) : 0.0,
            'unique_buyers' => $orders->pluck('phone')->filter()->unique()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $prior
     * @return array<string, float|null>
     */
    private function growth(array $current, array $prior): array
    {
        return [
            'orders_pct' => $this->pctChange((float) $prior['orders'], (float) $current['orders']),
            'gmv_placed_pct' => $this->pctChange((float) $prior['gmv_placed'], (float) $current['gmv_placed']),
            'delivered_pct' => $this->pctChange((float) $prior['delivered'], (float) $current['delivered']),
            'collected_pct' => $this->pctChange((float) $prior['collected'], (float) $current['collected']),
            'aov_pct' => $this->pctChange((float) $prior['aov'], (float) $current['aov']),
        ];
    }

    /**
     * @param  array<string, mixed>  $traction
     * @return array<string, mixed>
     */
    private function unitEconomics(Carbon $start, Carbon $end, array $traction): array
    {
        $orders = $this->ordersInWindow($start, $end)->where('status', 'delivered');
        $orderIds = $orders->pluck('id');

        $delivIncome = round((float) $orders->sum(fn (Order $o) => (float) $o->delivery_charge), 2);
        $courierCost = round((float) $orders->sum(fn (Order $o) => (float) $o->courier_charge), 2);
        $packaging = round((float) $orders->sum(fn (Order $o) => (float) $o->packaging_cost), 2);

        $lines = $orderIds->isEmpty()
            ? collect()
            : OrderProduct::query()->whereIn('order_id', $orderIds)->get([
                'quantity', 'returned_quantity', 'price', 'purchase_price',
            ]);

        $merchSell = 0.0;
        $sellKnown = 0.0;
        $cogsKnown = 0.0;
        $zeroLines = 0;
        $lineCount = 0;

        foreach ($lines as $line) {
            $qty = max(0, (int) $line->quantity - (int) ($line->returned_quantity ?? 0));
            $sell = (float) $line->price * $qty;
            $merchSell += $sell;
            $lineCount++;
            $pp = (float) $line->purchase_price;

            if ($pp > 0) {
                $sellKnown += $sell;
                $cogsKnown += $pp * $qty;
            } else {
                $zeroLines++;
            }
        }

        $merchSell = round($merchSell, 2);
        $sellKnown = round($sellKnown, 2);
        $cogsKnown = round($cogsKnown, 2);
        $gmPct = $sellKnown > 0
            ? round(($sellKnown - $cogsKnown) * 100 / $sellKnown, 1)
            : null;
        $merchGpKnown = round($sellKnown - $cogsKnown, 2);
        $merchGpEst = $gmPct !== null
            ? round($merchSell * ($gmPct / 100), 2)
            : null;
        $deliveryMargin = round($delivIncome - $courierCost, 2);
        $contributionEst = $merchGpEst !== null
            ? round($merchGpEst + $deliveryMargin - $packaging, 2)
            : null;
        $collected = (float) $traction['collected'];

        return [
            'merch_sell' => $merchSell,
            'sell_known' => $sellKnown,
            'cogs_known' => $cogsKnown,
            'cogs_coverage_pct' => $lineCount > 0
                ? round(($lineCount - $zeroLines) * 100 / $lineCount, 1)
                : 0.0,
            'gm_pct_known' => $gmPct,
            'merch_gp_known' => $merchGpKnown,
            'merch_gp_est' => $merchGpEst,
            'delivery_income' => $delivIncome,
            'courier_cost' => $courierCost,
            'delivery_margin' => $deliveryMargin,
            'packaging' => $packaging,
            'contribution_est' => $contributionEst,
            'contribution_pct_of_collected' => ($contributionEst !== null && $collected > 0)
                ? round($contributionEst * 100 / $collected, 1)
                : null,
        ];
    }

    /**
     * @return list<array{via: string, orders: int, gmv: float, share_pct: float}>
     */
    private function channels(Carbon $start, Carbon $end): array
    {
        $orders = $this->ordersInWindow($start, $end);
        $total = max(1, $orders->count());

        return $orders
            ->groupBy(fn (Order $o) => $o->placed_via ?: '(unknown)')
            ->map(function (Collection $group, string $via) use ($total): array {
                return [
                    'via' => $via,
                    'orders' => $group->count(),
                    'gmv' => round((float) $group->sum(fn (Order $o) => (float) $o->total), 2),
                    'share_pct' => round($group->count() * 100 / $total, 1),
                ];
            })
            ->sortByDesc('orders')
            ->values()
            ->all();
    }

    /**
     * @return list<array{city: string, orders: int, gmv: float}>
     */
    private function geos(Carbon $start, Carbon $end): array
    {
        return $this->ordersInWindow($start, $end)
            ->groupBy(fn (Order $o) => trim((string) $o->city) !== '' ? trim((string) $o->city) : '(blank)')
            ->map(fn (Collection $group, string $city): array => [
                'city' => $city,
                'orders' => $group->count(),
                'gmv' => round((float) $group->sum(fn (Order $o) => (float) $o->total), 2),
            ])
            ->sortByDesc('orders')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, orders: int, revenue: float}>
     */
    private function categories(Carbon $start, Carbon $end): array
    {
        $orderIds = $this->ordersInWindow($start, $end)
            ->where('status', 'delivered')
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('order_products as op')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('op.order_id', $orderIds)
            ->groupBy('c.id', 'c.name')
            ->orderByDesc(DB::raw('SUM(op.price * (op.quantity - COALESCE(op.returned_quantity, 0)))'))
            ->limit(8)
            ->get([
                DB::raw('COALESCE(c.name, "(uncategorized)") as name'),
                DB::raw('COUNT(DISTINCT op.order_id) as orders'),
                DB::raw('SUM(op.price * (op.quantity - COALESCE(op.returned_quantity, 0))) as revenue'),
            ]);

        return $rows->map(fn ($row): array => [
            'name' => (string) $row->name,
            'orders' => (int) $row->orders,
            'revenue' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * @return list<array{ym: string, label: string, orders: int, gmv: float, delivered: int, collected: float, dispatched: int}>
     */
    private function monthly(Carbon $start, Carbon $end): array
    {
        $cursor = $start->copy()->startOfMonth();
        $months = [];

        while ($cursor->lte($end)) {
            $ym = $cursor->format('Y-m');
            $months[$ym] = [
                'ym' => $ym,
                'label' => $cursor->format('M Y'),
                'orders' => 0,
                'gmv' => 0.0,
                'delivered' => 0,
                'collected' => 0.0,
                'dispatched' => 0,
            ];
            $cursor->addMonth();
        }

        foreach ($this->ordersInWindow($start, $end) as $order) {
            $placed = $order->placed_at?->timezone('Asia/Dhaka') ?? $order->created_at?->timezone('Asia/Dhaka');

            if ($placed === null) {
                continue;
            }

            $ym = $placed->format('Y-m');

            if (! isset($months[$ym])) {
                continue;
            }

            $months[$ym]['orders']++;
            $months[$ym]['gmv'] += (float) $order->total;

            if ($order->status === 'delivered') {
                $months[$ym]['delivered']++;
                $months[$ym]['collected'] += (float) $order->collected_amount;
            }

            if ($order->status === 'dispatched') {
                $months[$ym]['dispatched']++;
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['gmv'] = round($row['gmv'], 2);
            $row['collected'] = round($row['collected'], 2);

            return $row;
        }, $months));
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersInWindow(Carbon $start, Carbon $end): Collection
    {
        return Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('placed_at')
            ->whereBetween('placed_at', [
                $start->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
                $end->copy()->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'),
            ])
            ->get([
                'id', 'status', 'total', 'collected_amount', 'delivery_charge',
                'courier_charge', 'packaging_cost', 'placed_at', 'created_at',
                'placed_via', 'phone', 'city',
            ]);
    }

    private function pctChange(float $prior, float $current): ?float
    {
        if ($prior == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(($current - $prior) * 100 / $prior, 1);
    }
}
