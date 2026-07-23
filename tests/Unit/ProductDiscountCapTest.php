<?php

namespace Tests\Unit;

use App\Services\Orders\ProductDiscountCap;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ProductDiscountCapTest extends TestCase
{
    private ProductDiscountCap $cap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cap = new ProductDiscountCap;
    }

    public function test_uncapped_products_return_null_order_cap(): void
    {
        $items = Collection::make([
            ['max_discount' => null, 'quantity' => 2, 'line_total' => 1000],
        ]);

        $this->assertNull($this->cap->orderCouponCap($items));
    }

    public function test_order_coupon_cap_sums_per_unit(): void
    {
        $items = Collection::make([
            ['max_discount' => 50, 'quantity' => 2, 'line_total' => 1000],
            ['max_discount' => 20, 'quantity' => 1, 'line_total' => 400],
        ]);

        $this->assertSame(120.0, $this->cap->orderCouponCap($items));
    }

    public function test_allocate_clamps_to_line_cap(): void
    {
        $items = Collection::make([
            ['product_id' => 1, 'max_discount' => 10, 'quantity' => 1, 'line_total' => 500],
            ['product_id' => 2, 'max_discount' => null, 'quantity' => 1, 'line_total' => 500],
        ]);

        $result = $this->cap->allocateAcrossLines(200, $items);

        $this->assertTrue($result['capped']);
        $this->assertSame(110.0, $result['net_amount']); // 10 + 100 proportional share of uncapped
        $byProduct = Collection::make($result['allocations'])->keyBy('product_id');
        $this->assertSame(10.0, $byProduct[1]['amount']);
        $this->assertSame(100.0, $byProduct[2]['amount']);
    }

    public function test_allocate_rejects_when_no_room(): void
    {
        $items = Collection::make([
            ['product_id' => 1, 'max_discount' => 0, 'quantity' => 1, 'line_total' => 500],
        ]);

        $result = $this->cap->allocateAcrossLines(100, $items);

        $this->assertSame(0.0, $result['net_amount']);
        $this->assertTrue($result['capped']);
        $this->assertSame([], $result['allocations']);
    }

    public function test_prior_allocations_reduce_remaining_room(): void
    {
        $items = Collection::make([
            ['product_id' => 1, 'max_discount' => 50, 'quantity' => 1, 'line_total' => 1000],
        ]);

        $result = $this->cap->allocateAcrossLines(40, $items, [1 => 30.0]);

        $this->assertSame(20.0, $result['net_amount']);
        $this->assertTrue($result['capped']);
    }
}
