<?php

namespace App\Services\Orders;

/**
 * Immutable breakdown DTO returned by OrderTotalCalculator.
 *
 * All monetary values in taka (BDT), 2-decimal precision.
 */
readonly class OrderTotals
{
    public function __construct(
        /** Merchandise sell total (orders.subtotal). */
        public float $subtotal,

        /** What customer pays for delivery (orders.delivery_charge). */
        public float $deliveryCharge,

        /** What courier charges us (orders.courier_charge). */
        public float $courierCharge,

        /** Sum of all charge-type adjustment lines. */
        public float $charges,

        /** Sum of all discount + coupon adjustment lines. */
        public float $discounts,

        /**
         * Customer-facing total (COD amount).
         * total = max(0, subtotal + deliveryCharge + charges - discounts)
         */
        public float $total,

        /** Cost of goods sold: sum(purchase_price × effective_qty) over order items. */
        public float $cogs,

        /**
         * Net revenue (admin business metric).
         * netRevenue = subtotal - cogs + charges - discounts + deliveryCharge - courierCharge
         * May be negative — do NOT clamp.
         */
        public float $netRevenue,

        /** deliveryCharge - courierCharge. */
        public float $deliveryMargin,
    ) {}
}
