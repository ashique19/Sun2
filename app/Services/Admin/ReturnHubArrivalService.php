<?php

namespace App\Services\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Detects Steadfast return-parcel arrival at the Rampura hub from stored tracking messages.
 */
class ReturnHubArrivalService
{
    public const HUB_NAME = 'RAMPURA';

    public function isHubArrivalMessage(string $message): bool
    {
        $message = trim($message);

        if ($message === '') {
            return false;
        }

        return (bool) preg_match('/received\s+at\s+rampura\b/i', $message);
    }

    public function markArrived(Order $order, ?Carbon $arrivedAt = null): Order
    {
        if ($order->return_hub_arrived_at) {
            return $order;
        }

        $order->forceFill([
            'return_hub_arrived_at' => $arrivedAt ?? now(),
        ])->save();

        return $order->refresh();
    }

    /**
     * Stamp hub arrival when a Steadfast webhook/tracking message matches.
     * Applies to every inbound (`has_return`): product returns, partials, exchanges, H/R-only.
     * Auto-flags known inbound types first so Rampura arrivals are not dropped
     * while admin still needs to enter returned quantities.
     */
    public function observeMessage(Order $order, string $message, mixed $timestamp = null): bool
    {
        if (! $this->isHubArrivalMessage($message)) {
            return false;
        }

        if (! $order->has_return && $this->shouldAutoFlagReturnPending($order)) {
            app(OrderDeliveryReturnService::class)->setHasReturn($order, true);
            $order = $order->fresh();
        }

        if (! $order->has_return) {
            return false;
        }

        $arrivedAt = $this->parseTimestamp($timestamp) ?? now();
        $this->markArrived($order, $arrivedAt);

        return true;
    }

    /**
     * Known warehouse inbounds: exchanges, C/R, returned qty, and partial delivery.
     * Partial webhooks still wait for hub arrival before Return Pending is flagged.
     */
    public function shouldAutoFlagReturnPending(Order $order): bool
    {
        if ($this->isExchangeInbound($order)) {
            return true;
        }

        if (in_array($order->status, ['cancelled', 'returned'], true)) {
            return true;
        }

        $order->loadMissing('items');

        if ($order->items->contains(fn ($item) => (int) $item->returned_quantity > 0)) {
            return true;
        }

        return $this->hasPartialDeliverySignal($order);
    }

    /**
     * When a partial-delivery attention item is created after the hub stamp
     * already landed in courier logs, promote the order onto Return Pending.
     */
    public function promoteInboundIfExpected(Order $order): void
    {
        if ($order->has_return) {
            $this->syncFromCourierLogs($order);

            return;
        }

        if (! $this->shouldAutoFlagReturnPending($order)) {
            return;
        }

        if (! $this->isExchangeInbound($order) && ! $this->hasStoredHubArrivalMessage($order)) {
            return;
        }

        app(OrderDeliveryReturnService::class)->setHasReturn($order, true);
    }

    public function isExchangeInbound(Order $order): bool
    {
        if ((bool) $order->is_replacement) {
            return true;
        }

        return Order::query()->where('exchange_of_order_id', $order->id)->exists();
    }

    /**
     * Scan stored courier_data logs and stamp hub arrival when found.
     */
    public function syncFromCourierLogs(Order $order): bool
    {
        if ($order->return_hub_arrived_at || ! $order->has_return) {
            return false;
        }

        $order->loadMissing('courierLogs');

        foreach ($order->courierLogs as $log) {
            $data = is_array($log->api_data) ? $log->api_data : [];
            $message = trim((string) ($data['tracking_message'] ?? ''));

            if (! $this->isHubArrivalMessage($message)) {
                continue;
            }

            $arrivedAt = $this->parseTimestamp($data['updated_at'] ?? null) ?? $log->created_at;
            $this->markArrived($order, $arrivedAt instanceof Carbon ? $arrivedAt : Carbon::parse($arrivedAt));

            return true;
        }

        return false;
    }

    /**
     * Backfill hub stamps for Has Return orders that already have matching logs.
     */
    public function syncPendingFromStoredLogs(int $limit = 100): int
    {
        $orders = Order::query()
            ->where('has_return', true)
            ->whereNull('return_hub_arrived_at')
            ->with('courierLogs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $stamped = 0;

        foreach ($orders as $order) {
            if ($this->syncFromCourierLogs($order)) {
                $stamped++;
            }
        }

        return $stamped;
    }

    /**
     * Has Return parcels already stamped at the Rampura hub.
     */
    public function countArrivedAtHub(): int
    {
        return Order::query()
            ->where('has_return', true)
            ->whereNotNull('return_hub_arrived_at')
            ->count();
    }

    /**
     * Has Return parcels that have not reached the Rampura hub yet.
     */
    public function countOnTheWay(): int
    {
        return Order::query()
            ->where('has_return', true)
            ->whereNull('return_hub_arrived_at')
            ->count();
    }

    /**
     * Has Return orders whose parcels have arrived at the Rampura hub
     * (exchange, H/R-only, and product return-pending).
     *
     * @return Collection<int, Order>
     */
    public function ordersAwaitingReceive(int $limit = 50): Collection
    {
        $this->syncPendingFromStoredLogs($limit);

        return Order::query()
            ->with([
                'courier:id,name,slug',
                'items:id,order_id,name,quantity,returned_quantity,return_received,product_image,product_id',
                'exchangeOf:id,order_number',
                'replacements:id,order_number,exchange_of_order_id',
            ])
            ->where('has_return', true)
            ->whereNotNull('return_hub_arrived_at')
            ->orderByDesc('return_hub_arrived_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'order_number',
                'name',
                'area',
                'status',
                'has_return',
                'is_replacement',
                'exchange_of_order_id',
                'return_hub_arrived_at',
                'courier_id',
                'courier_tracker',
                'courier_consignment_id',
            ]);
    }

    private function hasPartialDeliverySignal(Order $order): bool
    {
        $hasPartialAttention = AdminAttentionItem::query()
            ->unresolved()
            ->where('order_id', $order->id)
            ->where('issue_type', AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH)
            ->get()
            ->contains(fn (AdminAttentionItem $item) => (bool) ($item->data['is_partial_delivery'] ?? false));

        if ($hasPartialAttention) {
            return true;
        }

        $order->loadMissing('courierLogs');

        foreach ($order->courierLogs as $log) {
            $data = is_array($log->api_data) ? $log->api_data : [];
            $haystack = strtolower(implode(' ', array_filter([
                (string) ($data['status'] ?? ''),
                (string) ($data['event'] ?? ''),
                (string) ($data['notification_type'] ?? ''),
                (string) ($data['tracking_message'] ?? ''),
            ])));

            if (str_contains($haystack, 'partial')) {
                return true;
            }
        }

        return false;
    }

    private function hasStoredHubArrivalMessage(Order $order): bool
    {
        if ($order->return_hub_arrived_at) {
            return true;
        }

        $order->loadMissing('courierLogs');

        foreach ($order->courierLogs as $log) {
            $data = is_array($log->api_data) ? $log->api_data : [];
            $message = trim((string) ($data['tracking_message'] ?? ''));

            if ($this->isHubArrivalMessage($message)) {
                return true;
            }
        }

        return false;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
