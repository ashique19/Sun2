<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderStatusHistory;

class OrderStatusService
{
    /**
     * @param  array<string, mixed>  $extraAttributes
     */
    public function update(
        Order $order,
        string $status,
        ?string $note = null,
        ?int $changedBy = null,
        array $extraAttributes = [],
    ): Order {
        $order->update(array_merge(['status' => $status], $extraAttributes));

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'note' => $note,
            'changed_by' => $changedBy ?? auth()->id(),
            'created_at' => now(),
        ]);

        if ($status === 'delivered' && ! $order->actual_delivery_date) {
            $order->update(['actual_delivery_date' => now()]);
        }

        return $order->fresh();
    }

    public function record(Order $order, string $note, ?int $changedBy = null): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => $order->status,
            'note' => $note,
            'changed_by' => $changedBy ?? auth()->id(),
            'created_at' => now(),
        ]);
    }

    public function recordPlacement(Order $order): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => 'new',
            'note' => 'Order placed via storefront.',
            'changed_by' => $order->user_id,
            'created_at' => $order->placed_at ?? now(),
        ]);
    }
}
