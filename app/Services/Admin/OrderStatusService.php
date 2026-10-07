<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Support\AdminOrderSegment;
use Illuminate\Support\Facades\Cache;

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
        $hadReturn = (bool) $order->has_return;

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

        $fresh = $order->fresh();

        if ($status === 'delivered') {
            $returns = app(OrderDeliveryReturnService::class);
            // Linked exchange delivered ⇒ expect the original defective parcel back (H/R).
            $returns->flagOriginalReturnAfterExchangeDelivery($fresh);
            // Courier partial delivery ⇒ this order expects a return parcel (Return Pending).
            $returns->flagReturnPendingAfterPartialDelivery($fresh->fresh());
            $fresh = $fresh->fresh();
        }

        if ((bool) $fresh->has_return !== $hadReturn) {
            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);
        }

        return $fresh;
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
