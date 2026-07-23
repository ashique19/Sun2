<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;

/**
 * Records COD / delivery-time payment collections without overwriting prior advances.
 */
class OrderDeliverySettlement
{
    public function __construct(
        private OrderPaymentRecorder $recorder,
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * Record a COD collection at delivery (admin, webhook, partial return).
     * Uses residual due when amount is omitted.
     */
    public function recordCollection(
        Order $order,
        ?float $amount = null,
        ?User $actor = null,
        ?array $meta = null,
        string $kind = 'settlement',
    ): void {
        $order->refresh();
        $amount = $amount ?? (float) $order->due_amount;
        $amount = round(max(0.0, $amount), 2);

        if ($amount <= 0) {
            $this->paymentSync->sync($order);

            return;
        }

        $this->recorder->record(
            order: $order,
            method: 'cod',
            amount: $amount,
            kind: $kind,
            reference: null,
            actor: $actor,
            meta: $meta,
        );
    }
}
