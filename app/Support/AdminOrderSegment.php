<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class AdminOrderSegment
{
    public const SEGMENTS = [
        'new' => 'New',
        'draft-ai' => 'Draft by AI',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'cancel-return' => 'Cancel & Return',
        'return-pending' => 'Return Pending',
        'all' => 'All',
    ];

    /** Segments that show order value on the dashboard tiles. */
    public const VALUE_SEGMENTS = ['new', 'dispatched'];

    public const COUNTS_CACHE_KEY = 'admin.order_segment_counts.v4';

    public const COUNTS_CACHE_TTL = 60;

    public static function apply(Builder $query, string $segment): Builder
    {
        return match ($segment) {
            'new' => $query->whereIn('status', ['new', 'confirmed']),
            'draft-ai' => $query->where('status', Order::STATUS_DRAFT),
            'dispatched' => $query->where('status', 'dispatched'),
            'delivered' => $query->where('status', 'delivered'),
            'cancel-return' => $query->whereIn('status', ['cancelled', 'returned']),
            'return-pending' => $query->where('has_return', true)->where('status', '!=', Order::STATUS_DRAFT),
            'all' => $query->where('status', '!=', Order::STATUS_DRAFT),
            default => $query,
        };
    }

    public static function isValid(string $segment): bool
    {
        return array_key_exists($segment, self::SEGMENTS);
    }

    public static function label(string $segment): string
    {
        return self::SEGMENTS[$segment] ?? 'Orders';
    }

    public static function count(string $segment): int
    {
        return self::apply(Order::query(), $segment)->count();
    }

    /**
     * @return array<string, int>
     */
    public static function counts(bool $fresh = false): array
    {
        return self::cachedMetrics($fresh)['counts'];
    }

    /**
     * Order value (sum of total) for dashboard tiles that show it.
     *
     * @return array<string, float>
     */
    public static function values(bool $fresh = false): array
    {
        return self::cachedMetrics($fresh)['values'];
    }

    public static function route(string $segment): string
    {
        return route('admin.orders.'.$segment);
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     values: array<string, float>
     * }
     */
    private static function cachedMetrics(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::COUNTS_CACHE_KEY);
        }

        return Cache::remember(self::COUNTS_CACHE_KEY, self::COUNTS_CACHE_TTL, function () {
            $byStatus = Order::query()
                ->selectRaw('status, COUNT(*) as aggregate, COALESCE(SUM(total), 0) as value_sum')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $returnPending = (int) Order::query()
                ->where('has_return', true)
                ->where('status', '!=', Order::STATUS_DRAFT)
                ->count();

            $draft = (int) ($byStatus[Order::STATUS_DRAFT]->aggregate ?? 0);
            $all = (int) $byStatus->sum(fn ($row) => (int) $row->aggregate) - $draft;

            $newValue = (float) ($byStatus['new']->value_sum ?? 0) + (float) ($byStatus['confirmed']->value_sum ?? 0);
            $dispatchedValue = (float) ($byStatus['dispatched']->value_sum ?? 0);

            return [
                'counts' => [
                    'new' => (int) ($byStatus['new']->aggregate ?? 0) + (int) ($byStatus['confirmed']->aggregate ?? 0),
                    'draft-ai' => $draft,
                    'dispatched' => (int) ($byStatus['dispatched']->aggregate ?? 0),
                    'delivered' => (int) ($byStatus['delivered']->aggregate ?? 0),
                    'cancel-return' => (int) ($byStatus['cancelled']->aggregate ?? 0) + (int) ($byStatus['returned']->aggregate ?? 0),
                    'return-pending' => $returnPending,
                    'all' => max(0, $all),
                ],
                'values' => [
                    'new' => round($newValue, 2),
                    'dispatched' => round($dispatchedValue, 2),
                ],
            ];
        });
    }
}
