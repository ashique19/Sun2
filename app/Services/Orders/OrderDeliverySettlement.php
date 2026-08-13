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
     * Stable COD ledger key for delivery settlement (webhook + admin deliver).
     */
    public static function settlementExternalId(int $orderId): string
    {
        return 'cod:settlement:order:'.$orderId;
    }

    /**
     * Stable COD ledger key for admin partial-return collections.
     */
    public static function partialReturnExternalId(int $orderId): string
    {
        return 'cod:partial_return:order:'.$orderId;
    }

    /**
     * Record a COD collection at delivery (admin, webhook, partial return).
     * Uses residual due when amount is omitted.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function recordCollection(
        Order $order,
        ?float $amount = null,
        ?User $actor = null,
        ?array $meta = null,
        string $kind = 'settlement',
        ?string $reference = null,
    ): void {
        $order->refresh();
        $due = round(max(0.0, (float) $order->due_amount), 2);
        $amount = $amount ?? $due;
        $amount = round(max(0.0, min((float) $amount, $due)), 2);

        if ($amount <= 0) {
            $this->paymentSync->sync($order);

            return;
        }

        $reference ??= $this->defaultReference($order, $meta);

        $this->recorder->record(
            order: $order,
            method: 'cod',
            amount: $amount,
            kind: $kind,
            reference: $reference,
            actor: $actor,
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function defaultReference(Order $order, ?array $meta): string
    {
        $source = is_string($meta['source'] ?? null) ? (string) $meta['source'] : 'settlement';

        return match ($source) {
            'admin_partial_return' => self::partialReturnExternalId((int) $order->id),
            default => self::settlementExternalId((int) $order->id),
        };
    }
}
