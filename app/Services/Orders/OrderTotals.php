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

        /** What courier charges us for delivery (orders.courier_charge). */
        public float $courierCharge,

        /** Direct packaging cost (orders.packaging_cost). */
        public float $packagingCost,

        /** Courier COD collection fee (derived; not stored on orders). */
        public float $codCharge,

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
         * netRevenue = subtotal - cogs + charges - discounts + deliveryCharge - courierCharge - packagingCost - codCharge
         * May be negative — do NOT clamp.
         *
         * Equivalent to grossProfit when remittance is the customer bill (typical unpaid COD).
         */
        public float $netRevenue,

        /** deliveryCharge - courierCharge. */
        public float $deliveryMargin,

        /**
         * Bill to customer (COD / invoice total).
         * billToCustomer = subtotal + deliveryCharge + charges - discounts (= total)
         */
        public float $billToCustomer,

        /**
         * Remittance base for courier receivable: collected COD when known, else expected COD bill.
         */
        public float $remittanceBase,

        /**
         * Expected/actual remittance from courier after their fees.
         * courierReceivable = remittanceBase - courierCharge - codCharge
         */
        public float $courierReceivable,

        /**
         * Gross profit after courier remittance and direct costs.
         * grossProfit = courierReceivable - cogs - packagingCost
         */
        public float $grossProfit,
    ) {}
}
