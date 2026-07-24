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

    public const COUNTS_CACHE_KEY = 'admin.order_segment_counts.v3';

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
        if ($fresh) {
            Cache::forget(self::COUNTS_CACHE_KEY);
        }

        return Cache::remember(self::COUNTS_CACHE_KEY, self::COUNTS_CACHE_TTL, function () {
            $statusCounts = Order::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $returnPending = (int) Order::query()
                ->where('has_return', true)
                ->where('status', '!=', Order::STATUS_DRAFT)
                ->count();

            $draft = (int) ($statusCounts[Order::STATUS_DRAFT] ?? 0);
            $all = (int) $statusCounts->sum() - $draft;

            return [
                'new' => (int) ($statusCounts['new'] ?? 0) + (int) ($statusCounts['confirmed'] ?? 0),
                'draft-ai' => $draft,
                'dispatched' => (int) ($statusCounts['dispatched'] ?? 0),
                'delivered' => (int) ($statusCounts['delivered'] ?? 0),
                'cancel-return' => (int) ($statusCounts['cancelled'] ?? 0) + (int) ($statusCounts['returned'] ?? 0),
                'return-pending' => $returnPending,
                'all' => max(0, $all),
            ];
        });
    }

    public static function route(string $segment): string
    {
        return route('admin.orders.'.$segment);
    }
}
