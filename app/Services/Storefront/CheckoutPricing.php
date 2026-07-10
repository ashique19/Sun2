<?php

namespace App\Services\Storefront;

use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;

class CheckoutPricing
{
    public function __construct(
        public float $subtotal,
        public float $deliveryCharge,
        public float $discount,
        public ?int $couponId,
        public float $total,
        public int $itemCount = 0,
    ) {}

    public static function calculate(
        float $subtotal,
        Area|City|string|null $location = null,
        int $itemCount = 0,
        ?Coupon $coupon = null,
    ): self {
        $delivery = self::deliveryCharge($location, $itemCount, $subtotal);
        $discount = $coupon ? app(CouponService::class)->discountAmount($coupon, $subtotal) : 0.0;
        $total = max(0, $subtotal + $delivery - $discount);

        return new self(
            subtotal: $subtotal,
            deliveryCharge: $delivery,
            discount: $discount,
            couponId: $coupon?->id,
            total: $total,
            itemCount: $itemCount,
        );
    }

    public static function deliveryCharge(Area|City|string|null $location, int $itemCount, float $subtotal = 0): float
    {
        if ($itemCount <= 0 || $subtotal <= 0) {
            return 0;
        }

        if ($location instanceof Area) {
            return $location->deliveryChargeFor($itemCount);
        }

        if ($location instanceof City) {
            return self::deliveryChargeForCityModel($location, $itemCount);
        }

        if (is_string($location) && $location !== '') {
            return self::deliveryChargeForCity($location, $itemCount);
        }

        return self::defaultOutsideCharge($itemCount);
    }

    public static function deliveryChargeForCity(string $city, int $itemCount): float
    {
        if ($itemCount <= 0) {
            return 0;
        }

        $normalized = strtolower(trim($city));
        $dhakaCities = array_map('strtolower', config('checkout.dhaka_cities', ['dhaka']));

        if (in_array($normalized, $dhakaCities, true)) {
            return self::defaultDhakaCityCharge($itemCount);
        }

        return self::defaultOutsideCharge($itemCount);
    }

    public static function deliveryChargeForCityModel(City $city, int $itemCount): float
    {
        if ($itemCount <= 0) {
            return 0;
        }

        if ($city->slug === 'dhaka-dhaka') {
            return self::defaultDhakaCityCharge($itemCount);
        }

        return self::defaultOutsideCharge($itemCount);
    }

    public static function defaultDhakaCityCharge(int $itemCount): float
    {
        return (float) ($itemCount <= 5
            ? config('checkout.dhaka_city_delivery_upto_5', 80)
            : config('checkout.dhaka_city_delivery_over_5', 150));
    }

    public static function defaultOutsideCharge(int $itemCount): float
    {
        return (float) ($itemCount <= 5
            ? config('checkout.outside_delivery_upto_5', 120)
            : config('checkout.outside_delivery_over_5', 200));
    }
}
