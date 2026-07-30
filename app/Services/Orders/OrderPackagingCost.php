<?php

namespace App\Services\Orders;

use App\Models\Order;

/**
 * Direct attributable packaging cost for an order.
 *
 * Common rates by product quantity:
 * 1 → ৳21, 2 → ৳30, 3+ → ৳41
 */
class OrderPackagingCost
{
    public function defaultForQuantity(int $productQuantity): float
    {
        if ($productQuantity <= 0) {
            return 0.0;
        }

        return match (true) {
            $productQuantity === 1 => 21.0,
            $productQuantity === 2 => 30.0,
            default => 41.0,
        };
    }

    public function productQuantity(Order $order): int
    {
        $order->loadMissing('items');

        return (int) $order->items->sum(fn ($item) => (int) $item->quantity);
    }

    public function defaultFor(Order $order): float
    {
        return $this->defaultForQuantity($this->productQuantity($order));
    }

    /**
     * Prefer a previously saved packaging cost; otherwise the qty-based default.
     */
    public function suggestedAmount(Order $order): float
    {
        $current = round((float) ($order->packaging_cost ?? 0), 2);

        if ($current > 0) {
            return $current;
        }

        return $this->defaultFor($order);
    }

    public function apply(Order $order, float $amount): void
    {
        $order->packaging_cost = round(max(0.0, $amount), 2);
        $order->save();
    }
}
