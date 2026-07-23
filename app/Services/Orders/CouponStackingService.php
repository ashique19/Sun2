<?php

namespace App\Services\Orders;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Validates and resolves a coupon for addition to an order's adjustment stack.
 *
 * Locked decisions (ORDER-ADJUSTMENTS-PLAN.md):
 * - Multiple coupons allowed on one order.
 * - Percent base = remaining merchandise subtotal after prior coupon/discount lines.
 * - min_order evaluated against original subtotal for every coupon.
 * - No soft cap on stack count.
 * - Admin can override expired/inactive coupons with audited note.
 * - Cancel does NOT decrement used_count in v1.
 * - Auto-cap: apply what fits; reject only if net amount would be 0.
 * - Charges cannot be negative.
 * - Integer taka rounding at persist for admin flows.
 */
class CouponStackingService
{
    public function __construct(
        private ProductDiscountCap $discountCap,
    ) {}

    /**
     * Validate a coupon for addition to a stack.
     *
     * @param  int  $orderId  For duplicate-on-order check
     * @param  list<array{coupon_id:int}>  $existingCouponLines  Coupon lines already on order
     * @param  bool  $adminOverride  Admin can skip active/date checks (with audited note)
     * @return array{valid: bool, message: string|null}
     */
    public function validate(
        Coupon $coupon,
        float $originalSubtotal,
        array $existingCouponLines = [],
        bool $adminOverride = false,
    ): array {
        // Duplicate coupon on same order
        $existingCouponIds = array_column($existingCouponLines, 'coupon_id');
        if (in_array($coupon->id, $existingCouponIds, true)) {
            return ['valid' => false, 'message' => "Coupon '{$coupon->code}' is already applied to this order."];
        }

        if (! $adminOverride) {
            if (! $coupon->is_active) {
                return ['valid' => false, 'message' => "Coupon '{$coupon->code}' is not active."];
            }

            if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                return ['valid' => false, 'message' => "Coupon '{$coupon->code}' is not active yet."];
            }

            if ($coupon->ends_at && $coupon->ends_at->isPast()) {
                return ['valid' => false, 'message' => "Coupon '{$coupon->code}' has expired."];
            }

            if (! $coupon->hasUsesRemaining()) {
                return ['valid' => false, 'message' => "Coupon '{$coupon->code}' has reached its usage limit."];
            }
        }

        // min_order always against original subtotal
        if ($originalSubtotal < (float) $coupon->min_order) {
            $min = number_format((float) $coupon->min_order, 0);

            return ['valid' => false, 'message' => "Minimum order amount for coupon '{$coupon->code}' is ৳{$min}."];
        }

        return ['valid' => true, 'message' => null];
    }

    /**
     * Compute the resolved taka amount for a coupon, respecting product max-discount caps.
     *
     * @param  float  $remainingSubtotal  Merchandise subtotal after prior coupon/discount lines (for percent base).
     * @param  float  $originalSubtotal   Original pre-discount subtotal (not used for amount but available for reference).
     * @param  Collection<int, \App\Models\OrderProduct|array>  $items  Order lines for cap allocation.
     * @param  array<int, float>  $alreadyAllocated  product_id => prior coupon amounts used (for cap tracking).
     * @return array{
     *     resolved_amount: float,
     *     capped: bool,
     *     meta: array,
     *     rejected: bool,
     *     rejection_message: string|null,
     * }
     */
    public function resolve(
        Coupon $coupon,
        float $remainingSubtotal,
        float $originalSubtotal,
        Collection $items,
        array $alreadyAllocated = [],
    ): array {
        // Compute unconstrained amount
        if ($coupon->type === 'percent') {
            $percent = (float) $coupon->value;
            $base = max(0.0, $remainingSubtotal);
            $unconstrained = round($base * ($percent / 100), 2);
            $meta = [
                'coupon_type' => 'percent',
                'percent'     => $percent,
                'base'        => round($base, 2),
            ];
        } else {
            $unconstrained = min((float) $coupon->value, max(0.0, $remainingSubtotal));
            $meta = ['coupon_type' => 'fixed'];
        }

        if ($unconstrained <= 0) {
            return [
                'resolved_amount'   => 0.0,
                'capped'            => false,
                'meta'              => $meta,
                'rejected'          => true,
                'rejection_message' => "Coupon '{$coupon->code}' would apply ৳0 (subtotal may be zero or fully discounted).",
            ];
        }

        // Apply product max-discount caps
        $allocation = $this->discountCap->allocateAcrossLines($unconstrained, $items, $alreadyAllocated);
        $resolvedAmount = $allocation['net_amount'];
        $capped = $allocation['capped'];

        if (isset($allocation['allocations'])) {
            $meta['allocations'] = $allocation['allocations'];
        }
        if ($capped) {
            $meta['capped'] = true;
            $meta['unconstrained_amount'] = $unconstrained;
        }

        if ($resolvedAmount <= 0) {
            return [
                'resolved_amount'   => 0.0,
                'capped'            => true,
                'meta'              => $meta,
                'rejected'          => true,
                'rejection_message' => "Coupon '{$coupon->code}' cannot be applied: product discount caps leave no room.",
            ];
        }

        return [
            'resolved_amount'   => $resolvedAmount,
            'capped'            => $capped,
            'meta'              => $meta,
            'rejected'          => false,
            'rejection_message' => null,
        ];
    }

    /**
     * Build a ready-to-persist adjustment line array from a resolved coupon.
     *
     * @param  array{resolved_amount:float,capped:bool,meta:array,...}  $resolved  Result from resolve()
     */
    public function buildAdjustmentLine(Coupon $coupon, array $resolved, int $sortOrder = 20): array
    {
        return [
            'type'       => 'coupon',
            'label'      => $coupon->code,
            'amount'     => $resolved['resolved_amount'],
            'coupon_id'  => $coupon->id,
            'source'     => 'checkout',
            'sort_order' => $sortOrder,
            'meta'       => $resolved['meta'],
        ];
    }

    /**
     * Compute the remaining merchandise subtotal after summing prior discount/coupon lines.
     *
     * @param  float  $originalSubtotal
     * @param  list<array{type:string,amount:float}>  $priorLines  Already-applied adjustment lines
     */
    public function remainingSubtotal(float $originalSubtotal, array $priorLines): float
    {
        $discountSum = 0.0;

        foreach ($priorLines as $line) {
            if (in_array($line['type'] ?? '', ['discount', 'coupon'], true)) {
                $discountSum += (float) ($line['amount'] ?? 0);
            }
        }

        return max(0.0, $originalSubtotal - $discountSum);
    }
}
