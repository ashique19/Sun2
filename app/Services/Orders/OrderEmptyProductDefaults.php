<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderProduct;

/**
 * Defaults when an order has no sellable product quantity.
 *
 * Packaging ৳21, COGS ৳50 (persisted as a placeholder order line).
 */
class OrderEmptyProductDefaults
{
    public const PACKAGING = 21.0;

    public const COGS = 50.0;

    public const COGS_LINE_NAME = '(no product)';

    public function hasNoProductQuantity(Order $order): bool
    {
        $order->loadMissing('items');

        return $this->sellableQuantity($order) < 1;
    }

    public function sellableQuantity(Order $order): int
    {
        $order->loadMissing('items');

        return (int) $order->items
            ->filter(fn (OrderProduct $item) => ! $this->isPlaceholderLine($item))
            ->sum(fn (OrderProduct $item) => max(
                0,
                (int) $item->quantity - (int) ($item->returned_quantity ?? 0)
            ));
    }

    public function isPlaceholderLine(OrderProduct $item): bool
    {
        return (string) $item->name === self::COGS_LINE_NAME;
    }
}
