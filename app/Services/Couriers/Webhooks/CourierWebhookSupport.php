<?php

namespace App\Services\Couriers\Webhooks;

use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Admin\OrderStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CourierWebhookSupport
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $invoiceKeys
     * @param  list<string>  $trackingKeys
     */
    public function findOrder(array $payload, array $invoiceKeys, array $trackingKeys): ?Order
    {
        foreach ($invoiceKeys as $key) {
            $invoice = isset($payload[$key]) ? trim((string) $payload[$key]) : '';

            if ($invoice === '') {
                continue;
            }

            $order = Order::query()->where('order_number', $invoice)->first();

            if ($order) {
                return $order;
            }
        }

        foreach ($trackingKeys as $key) {
            $tracking = $payload[$key] ?? null;

            if (! $tracking) {
                continue;
            }

            $order = Order::query()->where('courier_tracker', (string) $tracking)->first();

            if ($order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function process(Order $order, ?int $courierId, array $payload, callable $callback): void
    {
        DB::transaction(function () use ($order, $courierId, $payload, $callback) {
            CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'api_data' => $payload,
                'created_at' => now(),
            ]);

            $this->syncTracker($order, $payload, $courierId);

            $callback($order->fresh());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    public function applyStatus(
        Order $order,
        ?string $mappedStatus,
        string $message,
        array $payload = [],
        array $extra = [],
    ): void {
        if ($mappedStatus === null) {
            $this->recordHistory($order, $order->status, $message);

            return;
        }

        if ($mappedStatus === 'dispatched' && ! $order->dispatch_date) {
            $extra['dispatch_date'] = $this->parseTimestamp($payload['updated_at'] ?? $payload['timestamp'] ?? null) ?? now();
        }

        if ($mappedStatus === 'delivered') {
            $cod = $this->codAmount($payload, $order);
            $extra = array_merge($extra, [
                'payment_status' => 'paid',
                'collected_amount' => $cod,
                'paid_amount' => $cod,
                'due_amount' => 0,
                'actual_delivery_date' => $this->parseTimestamp($payload['updated_at'] ?? $payload['timestamp'] ?? null) ?? now(),
            ]);
        }

        if ($mappedStatus === $order->status && $extra === []) {
            $this->recordHistory($order, $order->status, $message);

            return;
        }

        $this->orderStatus->update($order, $mappedStatus, $message, null, $extra);
    }

    public function recordHistory(Order $order, string $status, string $note): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'note' => $note,
            'changed_by' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncTracker(Order $order, array $payload, ?int $courierId): void
    {
        $tracker = $payload['tracking_id']
            ?? $payload['tracking_code']
            ?? $payload['tracking_number']
            ?? $payload['consignment_id']
            ?? null;

        if (! $tracker || $order->courier_tracker) {
            return;
        }

        $order->update([
            'courier_tracker' => (string) $tracker,
            'courier_id' => $order->courier_id ?? $courierId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function codAmount(array $payload, Order $order): float
    {
        foreach (['collected_amount', 'cod_amount', 'amount_to_collect', 'collectable_amount'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return (float) $payload[$key];
            }
        }

        return (float) $order->cod_amount;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
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
