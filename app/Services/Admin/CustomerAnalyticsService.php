<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsService
{
    public const NO_CITY_KEY = '__none__';

    public const ORDERS_10_PLUS_KEY = '10plus';

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public function byCity(): array
    {
        /** @var array<string, array{label: string, users: array<int, true>}> $buckets */
        $buckets = [];

        $orderRows = DB::table('orders')
            ->whereIn('user_id', $this->customerUserIdsSubquery())
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('user_id')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('user_id', 'city')
            ->distinct()
            ->get();

        foreach ($orderRows as $row) {
            $label = trim((string) $row->city);
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            $buckets[$key]['label'] ??= $label;
            $buckets[$key]['users'][(int) $row->user_id] = true;
        }

        $addressRows = DB::table('addresses')
            ->leftJoin('cities', 'cities.id', '=', 'addresses.city_id')
            ->whereIn('addresses.user_id', $this->customerUserIdsSubquery())
            ->select('addresses.user_id', 'cities.name as city_name', 'addresses.city as city_text')
            ->get();

        foreach ($addressRows as $row) {
            $label = trim((string) ($row->city_name ?? ''));
            if ($label === '') {
                $label = trim((string) ($row->city_text ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            $buckets[$key]['label'] ??= $label;
            $buckets[$key]['users'][(int) $row->user_id] = true;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = [
                'key' => $bucket['label'],
                'label' => $bucket['label'],
                'count' => count($bucket['users']),
            ];
        }

        $noCityCount = $this->customerCountWithNoCity();
        if ($noCityCount > 0) {
            $rows[] = [
                'key' => self::NO_CITY_KEY,
                'label' => '(no city)',
                'count' => $noCityCount,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, count: int, orders_min: int, orders_max: ?int}>
     */
    public function byOrderCount(): array
    {
        $lifetimeCounts = User::query()
            ->role('customers')
            ->toBase()
            ->select('users.id')
            ->selectSub(function ($query) {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.user_id', 'users.id')
                    ->where('orders.status', '!=', Order::STATUS_DRAFT);
            }, 'lifetime_orders')
            ->pluck('lifetime_orders', 'id')
            ->map(fn ($count) => (int) $count);

        $grouped = $lifetimeCounts->groupBy(function (int $n) {
            return $n >= 10 ? self::ORDERS_10_PLUS_KEY : (string) $n;
        });

        $rows = [];
        foreach (range(0, 9) as $n) {
            $bucket = $grouped->get((string) $n);
            if ($bucket === null || $bucket->isEmpty()) {
                continue;
            }
            $rows[] = [
                'key' => (string) $n,
                'label' => $n === 1 ? '1 order' : $n.' orders',
                'count' => $bucket->count(),
                'orders_min' => $n,
                'orders_max' => $n,
            ];
        }

        $tenPlus = $grouped->get(self::ORDERS_10_PLUS_KEY);
        if ($tenPlus !== null && $tenPlus->isNotEmpty()) {
            $rows[] = [
                'key' => self::ORDERS_10_PLUS_KEY,
                'label' => '10+ orders',
                'count' => $tenPlus->count(),
                'orders_min' => 10,
                'orders_max' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, count: int, category_id: int}>
     */
    public function byCategory(): array
    {
        $rows = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->join('products', 'products.id', '=', 'order_products.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('orders.user_id', $this->customerUserIdsSubquery())
            ->where('orders.status', '!=', Order::STATUS_DRAFT)
            ->whereNotNull('orders.user_id')
            ->whereNotNull('products.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc(DB::raw('COUNT(DISTINCT orders.user_id)'))
            ->orderBy('categories.name')
            ->selectRaw('categories.id as category_id, categories.name as label, COUNT(DISTINCT orders.user_id) as aggregate_count')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => (string) $row->category_id,
            'label' => (string) $row->label,
            'count' => (int) $row->aggregate_count,
            'category_id' => (int) $row->category_id,
        ])->all();
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public function reportFor(string $pill): array
    {
        return match ($pill) {
            'city' => $this->byCity(),
            'orders' => array_map(
                fn (array $row) => [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'count' => $row['count'],
                ],
                $this->byOrderCount(),
            ),
            'category' => array_map(
                fn (array $row) => [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'count' => $row['count'],
                ],
                $this->byCategory(),
            ),
            default => [],
        };
    }

    public function customerCountWithNoCity(): int
    {
        return User::query()
            ->role('customers')
            ->whereDoesntHave('orders', function ($orders) {
                $orders->where('status', '!=', Order::STATUS_DRAFT)
                    ->whereNotNull('city')
                    ->where('city', '!=', '');
            })
            ->whereDoesntHave('addresses', function ($addresses) {
                $addresses->where(function ($a) {
                    $a->where(function ($b) {
                        $b->whereNotNull('city')->where('city', '!=', '');
                    })->orWhereHas('city', fn ($c) => $c->whereNotNull('name')->where('name', '!=', ''));
                });
            })
            ->count();
    }

    private function customerUserIdsSubquery()
    {
        return User::query()->role('customers')->select('users.id');
    }
}
