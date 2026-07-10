<?php

namespace App\Services\Storefront;

use App\Models\Coupon;

class CouponService
{
    public function findValid(string $code, float $subtotal): ?Coupon
    {
        $coupon = Coupon::query()
            ->active()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->first();

        if (! $coupon || ! $coupon->hasUsesRemaining()) {
            return null;
        }

        if ($subtotal < (float) $coupon->min_order) {
            return null;
        }

        return $coupon;
    }

    public function discountAmount(Coupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percent') {
            return round($subtotal * ((float) $coupon->value / 100), 2);
        }

        return min($subtotal, (float) $coupon->value);
    }

    public function validationMessage(string $code, float $subtotal): ?string
    {
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->first();

        if (! $coupon) {
            return 'Coupon code is invalid.';
        }

        if (! $coupon->is_active) {
            return 'This coupon is no longer active.';
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return 'This coupon is not active yet.';
        }

        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            return 'This coupon has expired.';
        }

        if (! $coupon->hasUsesRemaining()) {
            return 'This coupon has reached its usage limit.';
        }

        if ($subtotal < (float) $coupon->min_order) {
            return 'Minimum order amount for this coupon is ৳'.number_format($coupon->min_order, 0).'.';
        }

        return null;
    }
}
