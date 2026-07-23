<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;

/**
 * Records a new payment receipt against an order and syncs order caches.
 *
 * Admin v1: manual recording only (gateway automation is out of scope).
 * Each call creates one PaymentTransaction with status=completed.
 */
class OrderPaymentRecorder
{
    public function __construct(
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * Record a completed payment and sync order paid/due/status.
     *
     * @param  string  $method   Payment method code (cod|bkash|nagad|cash|bank|...)
     * @param  string  $kind     advance|partial|settlement|refund|adjustment
     * @param  string|null  $reference  External transaction ID / gateway reference
     * @param  array<string,mixed>|null  $meta  Extra evidence (gateway payload snippet, etc.)
     */
    public function record(
        Order $order,
        string $method,
        float $amount,
        string $kind = 'settlement',
        ?string $reference = null,
        ?User $actor = null,
        ?array $meta = null,
        ?\DateTimeInterface $paidAt = null,
    ): PaymentTransaction {
        $paymentMethod = PaymentMethod::query()->where('code', $method)->first();

        $transaction = PaymentTransaction::query()->create([
            'order_id'          => $order->id,
            'method'            => $method,
            'payment_method_id' => $paymentMethod?->id,
            'amount'            => round($amount, 2),
            'kind'              => $kind,
            'reference'         => $reference,
            'external_id'       => $reference, // denormalize for index lookups
            'status'            => 'completed',
            'paid_at'           => $paidAt ?? now(),
            'meta'              => $meta,
            'received_by'       => $actor?->id,
        ]);

        // Reload transactions relation so sync sees the new row
        $order->load('paymentTransactions');
        $this->paymentSync->sync($order);

        return $transaction;
    }
}
