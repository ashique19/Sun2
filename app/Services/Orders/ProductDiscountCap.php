<?php

namespace App\Services\Orders;

use Illuminate\Support\Collection;

/**
 * Computes and enforces per-product max-discount caps for coupon stacking.
 *
 * Locked decisions (ORDER-ADJUSTMENTS-PLAN.md):
 * - Cap applies to coupon lines; admin freeform discounts also respect cap by default.
 * - Allocation is proportional to line_total, then clamped per line to remaining cap room.
 * - Auto-cap: apply what fits; reject only when room is 0 for the entire order.
 * - Existing products with max_discount=null are treated as uncapped.
 */
class ProductDiscountCap
{
    /**
     * Total coupon discount capacity for the order.
     *
     * Returns null when every item is uncapped (max_discount = null on all lines).
     *
     * @param  Collection<int, \App\Models\OrderProduct|array{max_discount:float|null,quantity:int,line_total:float}>  $items
     */
    public function orderCouponCap(Collection $items): ?float
    {
        $hasAnyCap = false;
        $total = 0.0;

        foreach ($items as $item) {
            $maxDiscount = $this->itemMaxDiscount($item);

            if ($maxDiscount === null) {
                continue; // uncapped line does not constrain anything
            }

            $hasAnyCap = true;
            $qty = $this->itemQty($item);
            $total += $maxDiscount * $qty;
        }

        return $hasAnyCap ? round($total, 2) : null;
    }

    /**
     * Allocate a coupon amount across order lines proportionally, then clamp per-line caps.
     *
     * Returns the actually applicable amount (may be less than $couponAmount if capped),
     * plus an allocation breakdown for storage in adjustment meta.
     *
     * @param  Collection<int, \App\Models\OrderProduct|array{product_id:int,max_discount:float|null,quantity:int,line_total:float}>  $items
     * @param  array<int, float>  $alreadyAllocated  product_id => already-discounted amount from prior coupon lines
     * @return array{
     *     net_amount: float,
     *     capped: bool,
     *     allocations: list<array{product_id:int,amount:float}>,
     * }
     */
    public function allocateAcrossLines(
        float $couponAmount,
        Collection $items,
        array $alreadyAllocated = [],
    ): array {
        if ($couponAmount <= 0 || $items->isEmpty()) {
            return ['net_amount' => 0.0, 'capped' => false, 'allocations' => []];
        }

        $totalLineTotal = $items->sum(fn ($item) => $this->itemLineTotal($item));

        if ($totalLineTotal <= 0) {
            return ['net_amount' => 0.0, 'capped' => false, 'allocations' => []];
        }

        $allocations = [];
        $netAmount = 0.0;
        $anyCapped = false;

        foreach ($items as $item) {
            $productId = $this->itemProductId($item);
            $lineTotal = $this->itemLineTotal($item);
            $maxDiscount = $this->itemMaxDiscount($item);
            $qty = $this->itemQty($item);

            // Proportional share of this coupon amount for this line
            $proportionalShare = $totalLineTotal > 0
                ? $couponAmount * ($lineTotal / $totalLineTotal)
                : 0.0;

            // Remaining cap room for this line after prior coupon allocations
            if ($maxDiscount === null) {
                $capRoom = PHP_FLOAT_MAX;
            } else {
                $lineCap = $maxDiscount * $qty;
                $alreadyUsed = $alreadyAllocated[$productId] ?? 0.0;
                $capRoom = max(0.0, $lineCap - $alreadyUsed);
            }

            $lineAllocation = min($proportionalShare, $capRoom);

            if ($lineAllocation < $proportionalShare) {
                $anyCapped = true;
            }

            $lineAllocation = round($lineAllocation, 2);

            if ($lineAllocation > 0 && $productId !== null) {
                $allocations[] = ['product_id' => $productId, 'amount' => $lineAllocation];
                $netAmount += $lineAllocation;
            }
        }

        return [
            'net_amount'  => round($netAmount, 2),
            'capped'      => $anyCapped,
            'allocations' => $allocations,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function itemMaxDiscount(mixed $item): ?float
    {
        $v = is_array($item) ? ($item['max_discount'] ?? null) : $item->max_discount;

        return $v !== null ? (float) $v : null;
    }

    private function itemQty(mixed $item): int
    {
        return (int) (is_array($item) ? ($item['quantity'] ?? 1) : $item->quantity);
    }

    private function itemLineTotal(mixed $item): float
    {
        return (float) (is_array($item) ? ($item['line_total'] ?? 0) : $item->line_total);
    }

    private function itemProductId(mixed $item): ?int
    {
        $v = is_array($item) ? ($item['product_id'] ?? null) : $item->product_id;

        return $v !== null ? (int) $v : null;
    }
}
