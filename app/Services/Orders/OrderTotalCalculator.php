<?php

namespace App\Services\Orders;

use App\Models\OrderAdjustment;
use App\Models\OrderProduct;

/**
 * Single authoritative formula for all order money calculations.
 *
 * Used by checkout, admin order service, and adjustment sync.
 * Intentionally stateless — all inputs are passed on each call.
 *
 * Locked decisions (see ORDER-ADJUSTMENTS-PLAN.md):
 * - total is clamped to >= 0; net revenue is NOT clamped (lossy orders show negative)
 * - COGS uses (quantity - returned_quantity) × unit_cost (fallback: purchase_price)
 * - Charges cannot be negative (enforced at service layer; not here)
 * - Delivery and courier cost are independent fields, not adjustments
 * - Packaging cost is a direct attributable cost subtracted from net revenue
 * - COD charge is collected × courier COD % (Steadfast excludes their delivery fee)
 */
class OrderTotalCalculator
{
    /**
     * Calculate all order money totals.
     *
     * @param  iterable<OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
     * @param  iterable<OrderProduct|array{unit_cost?:float|int|null,purchase_price?:float|int,quantity:int,returned_quantity?:int}>  $items  Order lines for COGS
     */
    public function calculate(
        float $subtotal,
        float $deliveryCharge,
        float $courierCharge,
        iterable $adjustments = [],
        iterable $items = [],
        float $collectedAmount = 0.0,
        ?string $courierSlug = null,
        float $codPercentage = 1.0,
        float $packagingCost = 0.0,
        ?float $expectedCodRemittance = null,
        float $inHouseReceived = 0.0,
    ): OrderTotals {
        [$charges, $discounts] = $this->sumAdjustments($adjustments);
        $total = max(0.0, $subtotal + $deliveryCharge + $charges - $discounts);
        $cogs = $this->cogsFromItems($items);
        $packaging = max(0.0, $packagingCost);
        $deliveryMargin = $deliveryCharge - $courierCharge;

        // Courier-channel cash only. Customer bill and in-house payments are not remittance.
        // Pass expectedCodRemittance when the courier will collect a residual (unpaid COD).
        $collected = max(0.0, $collectedAmount);
        $remittanceBase = $collected > 0
            ? $collected
            : max(0.0, $expectedCodRemittance ?? 0.0);

        // COD % is a courier money-channel fee on remittance, not a delivery-channel fee.
        $codCharge = $this->codCharge(
            collectedAmount: $remittanceBase,
            courierDeliveryFee: $courierCharge,
            courierSlug: $courierSlug,
            codPercentage: $codPercentage,
        );
        $netRevenue = $subtotal - $cogs + $charges - $discounts + $deliveryCharge - $courierCharge - $packaging - $codCharge;
        $courierReceivable = $remittanceBase - $courierCharge - $codCharge;
        $inHouse = max(0.0, $inHouseReceived);
        $grossProfit = $inHouse + $courierReceivable - $cogs - $packaging;

        return new OrderTotals(
            subtotal: round($subtotal, 2),
            deliveryCharge: round($deliveryCharge, 2),
            courierCharge: round($courierCharge, 2),
            packagingCost: round($packaging, 2),
            codCharge: round($codCharge, 2),
            charges: round($charges, 2),
            discounts: round($discounts, 2),
            total: round($total, 2),
            cogs: round($cogs, 2),
            netRevenue: round($netRevenue, 2),
            deliveryMargin: round($deliveryMargin, 2),
            billToCustomer: round($total, 2),
            remittanceBase: round($remittanceBase, 2),
            courierReceivable: round($courierReceivable, 2),
            inHouseReceived: round($inHouse, 2),
            grossProfit: round($grossProfit, 2),
        );
    }

    /**
     * Courier COD collection fee.
     *
     * Steadfast total take = their delivery fee + this COD %:
     *   (collected − courier delivery fee) × cod%
     * Other couriers: collected × cod%
     */
    public function codCharge(
        float $collectedAmount,
        float $courierDeliveryFee = 0.0,
        ?string $courierSlug = null,
        float $codPercentage = 1.0,
    ): float {
        $collected = max(0.0, $collectedAmount);
        $rate = max(0.0, $codPercentage) / 100;

        if ($rate <= 0.0 || $collected <= 0.0) {
            return 0.0;
        }

        $base = strtolower(trim((string) $courierSlug)) === 'steadfast'
            ? max(0.0, $collected - max(0.0, $courierDeliveryFee))
            : $collected;

        return round($base * $rate, 2);
    }

    /**
     * Quick total-only calculation (no COGS / net-revenue). Useful at checkout.
     *
     * @param  iterable<OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
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
     * Prefer snapshotted unit_cost (total). Fall back to purchase_price for legacy rows.
     *
     * @param  iterable<OrderProduct|array{unit_cost?:float|int|null,purchase_price?:float|int,quantity:int,returned_quantity?:int}>  $items
     */
    public function cogsFromItems(iterable $items): float
    {
        $cogs = 0.0;

        foreach ($items as $item) {
            if (is_array($item)) {
                $unitCost = $this->unitCostFromArray($item);
                $qty = (int) ($item['quantity'] ?? 0);
                $returned = (int) ($item['returned_quantity'] ?? 0);
            } else {
                $unitCost = $item->effectiveUnitCost();
                $qty = (int) $item->quantity;
                $returned = (int) ($item->returned_quantity ?? 0);
            }

            $effectiveQty = max(0, $qty - $returned);
            $cogs += $unitCost * $effectiveQty;
        }

        return round($cogs, 2);
    }

    /**
     * @param  array{unit_cost?:float|int|null,purchase_price?:float|int}  $item
     */
    private function unitCostFromArray(array $item): float
    {
        if (array_key_exists('unit_cost', $item) && $item['unit_cost'] !== null && $item['unit_cost'] !== '') {
            return (float) $item['unit_cost'];
        }

        return (float) ($item['purchase_price'] ?? 0);
    }

    /**
     * @param  iterable<OrderAdjustment|array{type:string,amount:float|int}>  $adjustments
     * @return array{float, float} [charges, discounts]
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
