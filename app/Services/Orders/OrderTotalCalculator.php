<?php

namespace App\Services\Orders;

use Illuminate\Support\Collection;

/**
 * Single authoritative formula for all order money calculations.
 *
 * Used by checkout, admin order service, and adjustment sync.
 * Intentionally stateless — all inputs are passed on each call.
 *
 * Locked decisions (see ORDER-ADJUSTMENTS-PLAN.md):
 * - total is clamped to >= 0; net revenue is NOT clamped (lossy orders show negative)
 * - COGS uses (quantity - returned_quantity) × purchase_price
 * - Charges cannot be negative (enforced at service layer; not here)
 * - Delivery and courier cost are independent fields, not adjustments
 */
class OrderTotalCalculator
{
    /**
     * Calculate all order money totals.
     *
     * @param  iterable<\App\Models\OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
     * @param  iterable<\App\Models\OrderProduct|array{purchase_price:float|int,quantity:int,returned_quantity?:int}>  $items  Order lines for COGS
     */
    public function calculate(
        float $subtotal,
        float $deliveryCharge,
        float $courierCharge,
        iterable $adjustments = [],
        iterable $items = [],
    ): OrderTotals {
        [$charges, $discounts] = $this->sumAdjustments($adjustments);
        $total = max(0.0, $subtotal + $deliveryCharge + $charges - $discounts);
        $cogs = $this->cogsFromItems($items);
        $netRevenue = $subtotal - $cogs + $charges - $discounts + $deliveryCharge - $courierCharge;
        $deliveryMargin = $deliveryCharge - $courierCharge;

        return new OrderTotals(
            subtotal: round($subtotal, 2),
            deliveryCharge: round($deliveryCharge, 2),
            courierCharge: round($courierCharge, 2),
            charges: round($charges, 2),
            discounts: round($discounts, 2),
            total: round($total, 2),
            cogs: round($cogs, 2),
            netRevenue: round($netRevenue, 2),
            deliveryMargin: round($deliveryMargin, 2),
        );
    }

    /**
     * Quick total-only calculation (no COGS / net-revenue). Useful at checkout.
     *
     * @param  iterable<\App\Models\OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
     */
    public function customerTotal(
        float $subtotal,
        float $deliveryCharge,
        iterable $adjustments = [],
    ): float {
        [$charges, $discounts] = $this->sumAdjustments($adjustments);

        return round(max(0.0, $subtotal + $deliveryCharge + $charges - $discounts), 2);
    }

    /**
     * COGS from an iterable of order items.
     *
     * @param  iterable<\App\Models\OrderProduct|array{purchase_price:float|int,quantity:int,returned_quantity?:int}>  $items
     */
    public function cogsFromItems(iterable $items): float
    {
        $cogs = 0.0;

        foreach ($items as $item) {
            if (is_array($item)) {
                $purchasePrice = (float) ($item['purchase_price'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $returned = (int) ($item['returned_quantity'] ?? 0);
            } else {
                $purchasePrice = (float) $item->purchase_price;
                $qty = (int) $item->quantity;
                $returned = (int) ($item->returned_quantity ?? 0);
            }

            $effectiveQty = max(0, $qty - $returned);
            $cogs += $purchasePrice * $effectiveQty;
        }

        return round($cogs, 2);
    }

    /**
     * @param  iterable<\App\Models\OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
     * @return array{float, float}  [charges, discounts]
     */
    private function sumAdjustments(iterable $adjustments): array
    {
        $charges = 0.0;
        $discounts = 0.0;

        foreach ($adjustments as $adj) {
            if (is_array($adj)) {
                $type = (string) ($adj['type'] ?? '');
                $amount = (float) ($adj['amount'] ?? 0);
            } else {
                $type = (string) $adj->type;
                $amount = (float) $adj->amount;
            }

            if ($type === 'charge') {
                $charges += $amount;
            } else {
                // discount and coupon both reduce the total
                $discounts += $amount;
            }
        }

        return [$charges, $discounts];
    }
}
