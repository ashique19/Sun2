<?php

namespace App\Services\Couriers\Webhooks;

use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Admin\AdminAttentionService;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\OrderStatusService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Reseller\ResellerCommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CourierWebhookSupport
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ResellerCommissionService $resellerCommissions,
        private readonly OrderCourierChargeSync $courierChargeSync,
        private readonly OrderDeliverySettlement $deliverySettlement,
        private readonly OrderDeliveryReturnService $deliveryReturns,
        private readonly AdminAttentionService $adminAttention,
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
            // Serialize concurrent webhook/admin settles on the same order.
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $courierData = CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'api_data' => $payload,
                'created_at' => now(),
            ]);

            $this->syncTracker($order, $payload, $courierId);

            $phase = $this->phaseFromPayload($payload);
            $this->courierChargeSync->sync(
                order: $order->fresh(),
                fee: $this->courierChargeSync->parseFeeFromPayload($payload),
                actor: null,
                phase: $phase,
                meta: ['source' => 'webhook'],
                courierDataId: (int) $courierData->id,
            );

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

        if (in_array($mappedStatus, ['cancelled', 'returned'], true)) {
            $this->deliveryReturns->settleCourierCancelOrReturn(
                order: $order,
                status: $mappedStatus,
                note: $message,
                changedBy: null,
                applyCourierFeeDebit: true,
                extraAttributes: $extra,
            );

            return;
        }

        if ($mappedStatus === 'delivered') {
            $expected = $order->collectableAmount();
            $isPartial = $this->isPartialDeliveryPayload($payload);
            $cod = $this->adminAttention->resolveCollectedAmountFromPayload(
                $payload,
                $expected,
                $isPartial,
            );

            if ($isPartial
                || $cod === null
                || $this->adminAttention->isCodMismatchSignificant($expected, $cod)) {
                $this->holdDeliveryForReview(
                    order: $order,
                    payload: $payload,
                    expectedAmount: $expected,
                    collectedAmount: $cod,
                    isPartial: $isPartial,
                );

                return;
            }

            $this->deliverySettlement->recordCollection(
                order: $order,
                amount: $cod,
                actor: null,
                meta: ['source' => 'webhook', 'payload_event' => $payload['event'] ?? null],
            );
            $extra['actual_delivery_date'] = $this->parseTimestamp($payload['updated_at'] ?? $payload['timestamp'] ?? null) ?? now();
        }

        if ($mappedStatus === $order->status && $extra === []) {
            $this->recordHistory($order, $order->status, $message);

            return;
        }

        $this->orderStatus->update($order, $mappedStatus, $message, null, $extra);

        if ($mappedStatus === 'delivered') {
            $this->resellerCommissions->creditOnDelivered($order->fresh(['items']));
        }
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
     * Partial / COD-mismatch delivery: never auto-complete. Admin settles via qty modal.
     *
     * @param  array<string, mixed>  $payload
     */
    private function holdDeliveryForReview(
        Order $order,
        array $payload,
        float $expectedAmount,
        ?float $collectedAmount,
        bool $isPartial,
    ): void {
        $event = (string) ($payload['event'] ?? $payload['status'] ?? '');

        $this->adminAttention->createCodMismatch(
            order: $order,
            expectedAmount: $expectedAmount,
            collectedAmount: $collectedAmount,
            metadata: [
                'webhook_payload' => $payload,
                'reported_status' => $event,
                'is_partial_delivery' => $isPartial,
                'source' => str_starts_with(strtolower($event), 'order.')
                    ? 'pathao_webhook'
                    : 'webhook',
            ],
        );

        $collectedLabel = $collectedAmount === null ? 'not reported' : "৳{$collectedAmount}";
        $reviewNote = $isPartial
            ? 'Partial delivery reported'.($event !== '' ? " ({$event})" : '')
                .": Expected COD ৳{$expectedAmount}, collected {$collectedLabel}. Requires admin attention."
            : "COD mismatch: Expected ৳{$expectedAmount}, collected {$collectedLabel}. Courier reported delivered. Requires admin attention.";

        $this->recordHistory($order, $order->status, $reviewNote);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isPartialDeliveryPayload(array $payload): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($payload['event'] ?? ''),
            (string) ($payload['status'] ?? ''),
            (string) ($payload['notification_type'] ?? ''),
        ])));

        return str_contains($haystack, 'partial');
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
    private function phaseFromPayload(array $payload): string
    {
        $event = strtolower((string) ($payload['event'] ?? $payload['notification_type'] ?? ''));

        return match (true) {
            str_contains($event, 'deliver') => 'delivered',
            str_contains($event, 'cancel') => 'cancelled',
            str_contains($event, 'return') => 'cancelled',
            str_contains($event, 'track') => 'tracking',
            default => 'webhook',
        };
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
