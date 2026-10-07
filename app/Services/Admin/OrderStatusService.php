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

        // Linked exchange delivered ⇒ expect the original defective parcel back (H/R).
        // Partial delivery H/R is set only when admin submits returned quantities
        // via partialReturn — not on a bare Deliver click.
        if ($status === 'delivered') {
            app(OrderDeliveryReturnService::class)->flagOriginalReturnAfterExchangeDelivery($fresh);
            $fresh = $fresh->fresh();
        }

        if ((bool) $fresh->has_return !== $hadReturn) {
            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            // Partial Return sets has_return via extras; backfill any prior Rampura stamps.
            if ((bool) $fresh->has_return) {
                app(ReturnHubArrivalService::class)->syncFromCourierLogs($fresh);
                $fresh = $fresh->fresh();
            }
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
