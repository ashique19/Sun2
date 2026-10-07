<?php

namespace App\Services\Admin;

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
     * Applies to every Has Return order (`has_return`), including exchange / H/R-only.
     * If H/R was not set yet but this order clearly expects a return (linked exchange
     * original, replacement parcel, or partial-delivery signal), auto-flag H/R first
     * so the dashboard return-arrival list is not missed.
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
     * Orders that should expect a physical return even before admin settles qty.
     */
    public function shouldAutoFlagReturnPending(Order $order): bool
    {
        if ((bool) $order->is_replacement) {
            return true;
        }

        if (Order::query()->where('exchange_of_order_id', $order->id)->exists()) {
            return true;
        }

        return app(OrderDeliveryReturnService::class)->orderHadPartialDeliverySignal($order);
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
