<?php

namespace App\Services\Couriers;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Admin\OrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SteadfastWebhookProcessor
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $notificationType = (string) ($payload['notification_type'] ?? 'unknown');
        $order = $this->findOrder($payload);

        if (! $order) {
            Log::warning('Steadfast webhook: order not found.', [
                'invoice' => $payload['invoice'] ?? null,
                'tracking_id' => $payload['tracking_id'] ?? null,
            ]);

            return;
        }

        $courierId = Courier::query()->where('slug', 'steadfast')->value('id');

        DB::transaction(function () use ($order, $payload, $notificationType, $courierId) {
            CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'api_data' => $payload,
                'created_at' => now(),
            ]);

            $tracker = $payload['tracking_id'] ?? $payload['tracking_code'] ?? null;
            if ($tracker && ! $order->courier_tracker) {
                $order->update([
                    'courier_tracker' => (string) $tracker,
                    'courier_id' => $order->courier_id ?? $courierId,
                ]);
                $order->refresh();
            }

            if ($notificationType === 'delivery_status') {
                $this->handleDeliveryStatus($order, $payload);

                return;
            }

            if ($notificationType === 'tracking_update') {
                $this->handleTrackingUpdate($order, $payload);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDeliveryStatus(Order $order, array $payload): void
    {
        $steadfastStatus = strtolower((string) ($payload['status'] ?? ''));
        $mappedStatus = $this->mapDeliveryStatus($steadfastStatus);
        $message = (string) ($payload['tracking_message'] ?? 'Steadfast delivery status: '.$steadfastStatus);

        if ($mappedStatus === null) {
            $this->recordHistory($order, $order->status, $message);

            return;
        }

        $extra = [];

        if ($tracker = ($payload['tracking_id'] ?? $payload['tracking_code'] ?? null)) {
            $extra['courier_tracker'] = (string) $tracker;
        }

        if ($mappedStatus === 'dispatched' && ! $order->dispatch_date) {
            $extra['dispatch_date'] = $this->parseTimestamp($payload['updated_at'] ?? null) ?? now();
        }

        if ($mappedStatus === 'delivered') {
            $cod = isset($payload['cod_amount']) ? (float) $payload['cod_amount'] : (float) $order->cod_amount;
            $extra['payment_status'] = 'paid';
            $extra['collected_amount'] = $cod;
            $extra['paid_amount'] = $cod;
            $extra['due_amount'] = 0;
            $extra['actual_delivery_date'] = $this->parseTimestamp($payload['updated_at'] ?? null) ?? now();
        }

        if ($mappedStatus === $order->status && empty($extra)) {
            $this->recordHistory($order, $order->status, $message);

            return;
        }

        if ($mappedStatus !== $order->status || ! empty($extra)) {
            $this->orderStatus->update(
                $order,
                $mappedStatus,
                $message,
                null,
                $extra,
            );

            return;
        }

        $this->recordHistory($order, $order->status, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleTrackingUpdate(Order $order, array $payload): void
    {
        $message = (string) ($payload['tracking_message'] ?? 'Steadfast tracking update.');

        if (preg_match('/delivered successfully/i', $message) && $order->status !== 'delivered') {
            $this->orderStatus->update($order, 'delivered', $message, null, [
                'payment_status' => 'paid',
                'collected_amount' => $order->cod_amount,
                'paid_amount' => $order->cod_amount,
                'due_amount' => 0,
                'actual_delivery_date' => $this->parseTimestamp($payload['updated_at'] ?? null) ?? now(),
            ]);

            return;
        }

        $status = $order->status;
        if (in_array($status, ['new', 'confirmed'], true)) {
            $status = 'dispatched';
            $this->orderStatus->update($order, $status, $message, null, [
                'dispatch_date' => $order->dispatch_date ?? ($this->parseTimestamp($payload['updated_at'] ?? null) ?? now()),
            ]);

            return;
        }

        $this->recordHistory($order, $order->status, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrder(array $payload): ?Order
    {
        $invoice = isset($payload['invoice']) ? trim((string) $payload['invoice']) : '';
        if ($invoice !== '') {
            $order = Order::query()->where('order_number', $invoice)->first();
            if ($order) {
                return $order;
            }
        }

        $tracking = $payload['tracking_id'] ?? $payload['tracking_code'] ?? null;
        if ($tracking) {
            return Order::query()->where('courier_tracker', (string) $tracking)->first();
        }

        return null;
    }

    private function mapDeliveryStatus(string $steadfastStatus): ?string
    {
        return match ($steadfastStatus) {
            'delivered', 'partial_delivered', 'delivered_approval_pending' => 'delivered',
            'cancelled', 'cancelled_approval_pending' => 'cancelled',
            'returned', 'cancel and return' => 'returned',
            'pending', 'in_review', 'hold', 'in transit', 'processing' => 'dispatched',
            default => null,
        };
    }

    private function recordHistory(Order $order, string $status, string $note): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'note' => $note,
            'changed_by' => null,
            'created_at' => now(),
        ]);
    }

    private function parseTimestamp(?string $value): ?\Illuminate\Support\Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
