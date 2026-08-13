<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records a new payment receipt against an order and syncs order caches.
 *
 * Admin v1: manual recording only (gateway automation is out of scope).
 * Each call creates one PaymentTransaction with status=completed, unless
 * method+external_id already exists (idempotent replay).
 */
class OrderPaymentRecorder
{
    public function __construct(
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * Record a completed payment and sync order paid/due/status.
     *
     * When `$reference` is non-empty, a matching `(method, external_id)` row is
     * returned instead of inserting a duplicate (unique index + race-safe catch).
     *
     * @param  string  $method  Payment method code (cod|bkash|nagad|cash|bank|...)
     * @param  string  $kind  advance|partial|settlement|refund|adjustment
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
        $reference = $this->normalizeReference($reference);

        if ($reference !== null) {
            $existing = $this->findByMethodAndExternalId($method, $reference);
            if ($existing) {
                $order->load('paymentTransactions');
                $this->paymentSync->sync($order);

                return $existing;
            }
        }

        $paymentMethod = PaymentMethod::query()->where('code', $method)->first();

        try {
            $transaction = PaymentTransaction::query()->create([
                'order_id' => $order->id,
                'method' => $method,
                'payment_method_id' => $paymentMethod?->id,
                'amount' => round($amount, 2),
                'kind' => $kind,
                'reference' => $reference,
                'external_id' => $reference, // denormalize for index lookups
                'status' => 'completed',
                'paid_at' => $paidAt ?? now(),
                'meta' => $meta,
                'received_by' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($reference === null) {
                throw $e;
            }

            $existing = $this->findByMethodAndExternalId($method, $reference);
            if (! $existing) {
                throw $e;
            }

            $order->load('paymentTransactions');
            $this->paymentSync->sync($order);

            return $existing;
        }

        // Reload transactions relation so sync sees the new row
        $order->load('paymentTransactions');
        $this->paymentSync->sync($order);

        return $transaction;
    }

    private function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $reference = trim($reference);

        return $reference === '' ? null : $reference;
    }

    private function findByMethodAndExternalId(string $method, string $externalId): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('method', $method)
            ->where('external_id', $externalId)
            ->first();
    }
}
