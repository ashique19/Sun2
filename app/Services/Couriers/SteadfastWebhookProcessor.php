<?php

namespace App\Services\Couriers;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Admin\AdminAttentionService;
use App\Services\Admin\OrderStatusService;
use App\Services\Admin\ReturnHubArrivalService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Reseller\ResellerCommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SteadfastWebhookProcessor
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ResellerCommissionService $resellerCommissions,
        private readonly OrderCourierChargeSync $courierChargeSync,
        private readonly OrderDeliverySettlement $deliverySettlement,
        private readonly AdminAttentionService $adminAttention,
        private readonly ReturnHubArrivalService $returnHubArrival,
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
            $courierData = CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'api_data' => $payload,
                'created_at' => now(),
            ]);

            $this->courierChargeSync->sync(
                order: $order,
                fee: $this->courierChargeSync->parseFeeFromPayload($payload),
                actor: null,
                phase: $notificationType === 'delivery_status' ? 'delivered' : 'tracking',
                meta: ['source' => 'steadfast_webhook'],
                courierDataId: (int) $courierData->id,
            );

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
            } elseif ($notificationType === 'tracking_update') {
                $this->handleTrackingUpdate($order, $payload);
            }

            $message = (string) ($payload['tracking_message'] ?? '');
            $this->returnHubArrival->observeMessage(
                $order->fresh(),
                $message,
                $payload['updated_at'] ?? null,
            );
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

        // Handle delivery status with COD validation / partial-delivery review.
        if ($mappedStatus === 'delivered') {
            $order->refresh();
            $expectedAmount = $order->collectableAmount();
            $collectedAmount = $this->collectedAmountFromPayload($payload, $expectedAmount);
            $isPartial = $this->isPartialDeliveryStatus($steadfastStatus);
            $needsAttention = $isPartial
                || $this->adminAttention->isCodMismatchSignificant($expectedAmount, $collectedAmount);

            if ($needsAttention) {
                $this->adminAttention->createCodMismatch(
                    order: $order,
                    expectedAmount: $expectedAmount,
                    collectedAmount: $collectedAmount,
                    metadata: [
                        'steadfast_status' => $steadfastStatus,
                        'webhook_payload' => $payload,
                        'reported_status' => $steadfastStatus,
                        'is_partial_delivery' => $isPartial,
                    ],
                );

                $mismatchMessage = $isPartial
                    ? "Partial delivery reported ({$steadfastStatus}): Expected COD ৳{$expectedAmount}, collected ৳{$collectedAmount}. Requires admin attention."
                    : "COD mismatch: Expected ৳{$expectedAmount}, collected ৳{$collectedAmount}. Courier reported status: {$steadfastStatus}. Requires admin attention.";
                $this->recordHistory($order, $order->status, $mismatchMessage);

                // Keep order as dispatched — never auto-complete delivery on mismatch/partial.
                return;
            }

            // Full delivery with matching COD — proceed normally.
            $this->deliverySettlement->recordCollection(
                order: $order,
                amount: $collectedAmount,
                actor: null,
                meta: ['source' => 'steadfast_webhook'],
            );
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

            if ($mappedStatus === 'delivered') {
                $this->resellerCommissions->creditOnDelivered($order->fresh(['items']));
            }

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
            $order->refresh();
            $expectedAmount = $order->collectableAmount();
            $collectedAmount = $this->collectedAmountFromPayload($payload, $expectedAmount);

            if ($this->adminAttention->isCodMismatchSignificant($expectedAmount, $collectedAmount)) {
                $this->adminAttention->createCodMismatch(
                    order: $order,
                    expectedAmount: $expectedAmount,
                    collectedAmount: $collectedAmount,
                    metadata: [
                        'tracking_message' => $message,
                        'webhook_payload' => $payload,
                        'reported_status' => 'tracking_delivered',
                        'source' => 'steadfast_tracking',
                    ],
                );

                $this->recordHistory(
                    $order,
                    $order->status,
                    "COD mismatch: Expected ৳{$expectedAmount}, collected ৳{$collectedAmount}. Courier reported delivered via tracking update. Requires admin attention.",
                );

                return;
            }

            $this->deliverySettlement->recordCollection(
                order: $order,
                amount: $collectedAmount,
                actor: null,
                meta: ['source' => 'steadfast_tracking'],
            );

            $this->orderStatus->update($order, 'delivered', $message, null, [
                'actual_delivery_date' => $this->parseTimestamp($payload['updated_at'] ?? null) ?? now(),
            ]);

            $this->resellerCommissions->creditOnDelivered($order->fresh(['items']));

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
            $order = Order::query()->where('courier_tracker', (string) $tracking)->first();
            if ($order) {
                return $order;
            }
        }

        $consignmentId = $payload['consignment_id'] ?? null;
        if ($consignmentId !== null && $consignmentId !== '') {
            return Order::query()
                ->where('courier_consignment_id', (string) $consignmentId)
                ->first();
        }

        return null;
    }

    private function mapDeliveryStatus(string $steadfastStatus): ?string
    {
        return match ($steadfastStatus) {
            'delivered',
            'delivered_approval_pending',
            'partial_delivered',
            'partial_delivered_approval_pending' => 'delivered',
            'cancelled', 'cancelled_approval_pending' => 'cancelled',
            'returned', 'cancel and return' => 'returned',
            'pending', 'in_review', 'hold', 'in transit', 'processing' => 'dispatched',
            default => null,
        };
    }

    private function isPartialDeliveryStatus(string $steadfastStatus): bool
    {
        return in_array($steadfastStatus, [
            'partial_delivered',
            'partial_delivered_approval_pending',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function collectedAmountFromPayload(array $payload, float $fallback): float
    {
        foreach (['collected_amount', 'cod_amount', 'amount_to_collect', 'collectable_amount'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                continue;
            }

            return round((float) $payload[$key], 2);
        }

        return round($fallback, 2);
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

    private function parseTimestamp(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
