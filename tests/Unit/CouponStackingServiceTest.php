<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Services\Orders\CouponStackingService;
use App\Services\Orders\ProductDiscountCap;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CouponStackingServiceTest extends TestCase
{
    private CouponStackingService $stacking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stacking = new CouponStackingService(new ProductDiscountCap);
    }

    public function test_rejects_duplicate_coupon(): void
    {
        $coupon = new Coupon(['code' => 'SAVE10', 'min_order' => 0, 'is_active' => true]);
        $coupon->id = 5;

        $result = $this->stacking->validate($coupon, 1000, [['coupon_id' => 5]]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('already applied', (string) $result['message']);
    }

    public function test_min_order_against_original_subtotal(): void
    {
        $coupon = new Coupon([
            'code' => 'BIG',
            'min_order' => 2000,
            'is_active' => true,
            'type' => 'fixed',
            'value' => 100,
        ]);
        $coupon->id = 1;

        $result = $this->stacking->validate($coupon, 1500);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Minimum order', (string) $result['message']);
    }

    public function test_percent_uses_remaining_subtotal(): void
    {
        $coupon = new Coupon([
            'code' => 'PCT10',
            'min_order' => 0,
            'is_active' => true,
            'type' => 'percent',
            'value' => 10,
        ]);
        $coupon->id = 2;

        $resolved = $this->stacking->resolve(
            coupon: $coupon,
            remainingSubtotal: 800,
            originalSubtotal: 1000,
            items: Collection::make([
                ['product_id' => 1, 'max_discount' => null, 'quantity' => 1, 'line_total' => 1000],
            ]),
        );

        $this->assertFalse($resolved['rejected']);
        $this->assertSame(80.0, $resolved['resolved_amount']);
        $this->assertSame(10.0, $resolved['meta']['percent'] ?? null);
    }

    public function test_auto_caps_when_partial_room(): void
    {
        $coupon = new Coupon([
            'code' => 'FIXED200',
            'min_order' => 0,
            'is_active' => true,
            'type' => 'fixed',
            'value' => 200,
        ]);
        $coupon->id = 3;

        $resolved = $this->stacking->resolve(
            coupon: $coupon,
            remainingSubtotal: 1000,
            originalSubtotal: 1000,
            items: Collection::make([
                ['product_id' => 1, 'max_discount' => 50, 'quantity' => 1, 'line_total' => 1000],
            ]),
        );

        $this->assertFalse($resolved['rejected']);
        $this->assertTrue($resolved['capped']);
        $this->assertSame(50.0, $resolved['resolved_amount']);
    }

    public function test_rejects_when_cap_room_is_zero(): void
    {
        $coupon = new Coupon([
            'code' => 'ZERO',
            'min_order' => 0,
            'is_active' => true,
            'type' => 'fixed',
            'value' => 100,
        ]);
        $coupon->id = 4;

        $resolved = $this->stacking->resolve(
            coupon: $coupon,
            remainingSubtotal: 1000,
            originalSubtotal: 1000,
            items: Collection::make([
                ['product_id' => 1, 'max_discount' => 0, 'quantity' => 1, 'line_total' => 1000],
            ]),
        );

        $this->assertTrue($resolved['rejected']);
        $this->assertSame(0.0, $resolved['resolved_amount']);
    }
}
