<?php

namespace App\Services\Storefront;

use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;
use App\Services\Orders\CouponStackingService;
use App\Services\Orders\OrderTotalCalculator;
use Illuminate\Support\Collection;

class CheckoutPricing
{
    /** @var list<array{type:string,label:string,amount:float,coupon_id?:int,source:string,sort_order:int,meta?:array}> */
    public array $adjustmentLines = [];

    /** @var list<array{code:string,amount:float,capped:bool,rejected:bool,message:?string}> */
    public array $couponResults = [];

    public function __construct(
        public float $subtotal,
        public float $deliveryCharge,
        public float $discount,
        public ?int $couponId,
        public float $total,
        public int $itemCount = 0,
    ) {}

    /**
     * @param  Coupon|list<Coupon>|null  $coupons
     */
    public static function calculate(
        float $subtotal,
        Area|City|string|null $location = null,
        int $itemCount = 0,
        Coupon|array|null $coupons = null,
        ?Collection $cartLines = null,
    ): self {
        $delivery = self::deliveryCharge($location, $itemCount, $subtotal);
        $couponList = self::normalizeCoupons($coupons);
        $stack = self::resolveCouponStack($couponList, $subtotal, $cartLines);

        $total = app(OrderTotalCalculator::class)->customerTotal(
            subtotal: $subtotal,
            deliveryCharge: $delivery,
            adjustments: $stack['lines'],
        );

        $pricing = new self(
            subtotal: $subtotal,
            deliveryCharge: $delivery,
            discount: $stack['discount'],
            couponId: $stack['coupon_id'],
            total: $total,
            itemCount: $itemCount,
        );
        $pricing->adjustmentLines = $stack['lines'];
        $pricing->couponResults = $stack['results'];

        return $pricing;
    }

    /**
     * @param  list<Coupon>  $coupons
     * @return array{
     *     lines: list<array{type:string,label:string,amount:float,coupon_id?:int,source:string,sort_order:int,meta?:array}>,
     *     results: list<array{code:string,amount:float,capped:bool,rejected:bool,message:?string}>,
     *     discount: float,
     *     coupon_id: ?int,
     * }
     */
    public static function resolveCouponStack(array $coupons, float $subtotal, ?Collection $cartLines = null): array
    {
        if ($coupons === []) {
            return [
                'lines' => [],
                'results' => [],
                'discount' => 0.0,
                'coupon_id' => null,
            ];
        }

        $stacking = app(CouponStackingService::class);
        $capLines = self::capLinesFromCart($cartLines);
        $priorLines = [];
        $adjustmentLines = [];
        $results = [];
        $alreadyAllocated = [];
        $discount = 0.0;
        $couponId = null;

        foreach ($coupons as $coupon) {
            $existingCouponLines = array_values(array_filter(
                $priorLines,
                fn (array $line) => ($line['type'] ?? '') === 'coupon',
            ));

            $validation = $stacking->validate($coupon, $subtotal, $existingCouponLines);
            if (! $validation['valid']) {
                $results[] = [
                    'code' => $coupon->code,
                    'amount' => 0.0,
                    'capped' => false,
                    'rejected' => true,
                    'message' => $validation['message'],
                ];

                continue;
            }

            $remaining = $stacking->remainingSubtotal($subtotal, $priorLines);
            $resolved = $stacking->resolve(
                coupon: $coupon,
                remainingSubtotal: $remaining,
                originalSubtotal: $subtotal,
                items: $capLines,
                alreadyAllocated: $alreadyAllocated,
            );

            if ($resolved['rejected'] || $resolved['resolved_amount'] <= 0) {
                $results[] = [
                    'code' => $coupon->code,
                    'amount' => 0.0,
                    'capped' => (bool) ($resolved['capped'] ?? false),
                    'rejected' => true,
                    'message' => $resolved['rejection_message'],
                ];

                continue;
            }

            $line = $stacking->buildAdjustmentLine(
                $coupon,
                $resolved,
                20 + (count($adjustmentLines) * 10),
            );
            $adjustmentLines[] = $line;
            $priorLines[] = $line;
            $discount += (float) $resolved['resolved_amount'];
            $couponId ??= $coupon->id;

            if (isset($resolved['meta']['allocations']) && is_array($resolved['meta']['allocations'])) {
                foreach ($resolved['meta']['allocations'] as $productId => $allocated) {
                    $alreadyAllocated[(int) $productId] = ($alreadyAllocated[(int) $productId] ?? 0.0) + (float) $allocated;
                }
            }

            $results[] = [
                'code' => $coupon->code,
                'amount' => (float) $resolved['resolved_amount'],
                'capped' => (bool) $resolved['capped'],
                'rejected' => false,
                'message' => $resolved['capped']
                    ? "Coupon '{$coupon->code}' was capped by product max-discount limits."
                    : null,
            ];
        }

        return [
            'lines' => $adjustmentLines,
            'results' => $results,
            'discount' => round($discount, 2),
            'coupon_id' => $couponId,
        ];
    }

    /**
     * @param  Coupon|list<Coupon>|null  $coupons
     * @return list<Coupon>
     */
    private static function normalizeCoupons(Coupon|array|null $coupons): array
    {
        if ($coupons instanceof Coupon) {
            return [$coupons];
        }

        if (is_array($coupons)) {
            return array_values(array_filter($coupons, fn ($coupon) => $coupon instanceof Coupon));
        }

        return [];
    }

    /**
     * @return Collection<int, array{product_id:int,max_discount:float|null,quantity:int,line_total:float}>
     */
    private static function capLinesFromCart(?Collection $cartLines): Collection
    {
        if (! $cartLines || $cartLines->isEmpty()) {
            return collect();
        }

        return $cartLines->map(fn (array $line) => [
            'product_id' => (int) $line['product']->id,
            'max_discount' => $line['product']->max_discount !== null
                ? (float) $line['product']->max_discount
                : null,
            'quantity' => (int) $line['quantity'],
            'line_total' => (float) $line['line_total'],
        ]);
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
