<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CheckoutPricing;
use App\Services\Storefront\OrderPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CouponCheckoutUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Product, 1: Area, 2: Coupon}
     */
    private function seedCheckout(int $usageLimit = 1, int $usedCount = 0): array
    {
        $product = Product::query()->create([
            'name' => 'Test Kurti',
            'slug' => 'test-kurti-coupon',
            'sku' => 'TK-COUPON-1',
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 20,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-coupon',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Gulshan',
            'slug' => 'dhaka-gulshan-coupon',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        $coupon = Coupon::query()->create([
            'code' => 'ONCEONLY',
            'type' => 'fixed',
            'value' => 50,
            'min_order' => 0,
            'usage_limit' => $usageLimit,
            'used_count' => $usedCount,
            'is_active' => true,
        ]);

        return [$product, $area, $coupon];
    }

    private function placeWithCoupon(Product $product, Area $area, Coupon $coupon): void
    {
        $cart = app(CartService::class);
        $cart->clear();
        $cart->add($product->id, 1);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            [$coupon->fresh()],
            $cart->lines(),
        );

        app(OrderPlacer::class)->place($cart, $pricing, [
            'name' => 'Customer',
            'phone' => '01710000000',
            'email' => '',
            'address' => 'House 1',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ], [$coupon->fresh()]);
    }

    #[Test]
    public function placing_order_increments_coupon_used_count_under_lock(): void
    {
        [$product, $area, $coupon] = $this->seedCheckout(usageLimit: 2, usedCount: 0);

        $this->placeWithCoupon($product, $area, $coupon);

        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    #[Test]
    public function placing_order_rejects_coupon_when_usage_limit_already_reached(): void
    {
        [$product, $area, $coupon] = $this->seedCheckout(usageLimit: 1, usedCount: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Coupon 'ONCEONLY' has reached its usage limit.");

        $this->placeWithCoupon($product, $area, $coupon);
    }
}
