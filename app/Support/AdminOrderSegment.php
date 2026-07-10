<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class AdminOrderSegment
{
    public const SEGMENTS = [
        'new' => 'New',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'cancel-return' => 'Cancel & Return',
        'return-pending' => 'Return Pending',
    ];

    public static function apply(Builder $query, string $segment): Builder
    {
        return match ($segment) {
            'new' => $query->whereIn('status', ['new', 'confirmed']),
            'dispatched' => $query->where('status', 'dispatched'),
            'delivered' => $query->where('status', 'delivered'),
            'cancel-return' => $query->whereIn('status', ['cancelled', 'returned']),
            'return-pending' => $query->where('has_return', true),
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
    public static function counts(): array
    {
        $counts = [];

        foreach (array_keys(self::SEGMENTS) as $segment) {
            $counts[$segment] = self::count($segment);
        }

        return $counts;
    }

    public static function route(string $segment): string
    {
        return route('admin.orders.'.$segment);
    }
}
