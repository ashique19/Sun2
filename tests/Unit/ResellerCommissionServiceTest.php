<?php

namespace Tests\Unit;

use App\Models\OrderProduct;
use App\Services\Reseller\ResellerCommissionService;
use PHPUnit\Framework\TestCase;

class ResellerCommissionServiceTest extends TestCase
{
    public function test_line_commission_adds_markup_above_base(): void
    {
        $service = new ResellerCommissionService;
        $item = new OrderProduct([
            'quantity' => 2,
            'returned_quantity' => 0,
            'base_price' => 1000,
            'price' => 1200,
            'commission_rate' => 50,
        ]);

        // (50 + 200) * 2 = 500
        $this->assertSame(500.0, $service->lineCommission($item));
    }

    public function test_line_commission_ignores_price_below_base(): void
    {
        $service = new ResellerCommissionService;
        $item = new OrderProduct([
            'quantity' => 1,
            'returned_quantity' => 0,
            'base_price' => 1000,
            'price' => 900,
            'commission_rate' => 40,
        ]);

        $this->assertSame(40.0, $service->lineCommission($item));
    }

    public function test_line_commission_subtracts_returned_quantity(): void
    {
        $service = new ResellerCommissionService;
        $item = new OrderProduct([
            'quantity' => 3,
            'returned_quantity' => 1,
            'base_price' => 500,
            'price' => 500,
            'commission_rate' => 25,
        ]);

        $this->assertSame(50.0, $service->lineCommission($item));
    }
}
