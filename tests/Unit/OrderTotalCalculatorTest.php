<?php

namespace Tests\Unit;

use App\Services\Orders\OrderTotalCalculator;
use PHPUnit\Framework\TestCase;

class OrderTotalCalculatorTest extends TestCase
{
    private OrderTotalCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new OrderTotalCalculator;
    }

    public function test_customer_total_with_charges_and_discounts(): void
    {
        $total = $this->calc->customerTotal(
            subtotal: 1000,
            deliveryCharge: 80,
            adjustments: [
                ['type' => 'charge', 'amount' => 50],
                ['type' => 'discount', 'amount' => 100],
                ['type' => 'coupon', 'amount' => 50],
            ],
        );

        // 1000 + 80 + 50 - 100 - 50 = 980
        $this->assertSame(980.0, $total);
    }

    public function test_customer_total_clamped_to_zero(): void
    {
        $total = $this->calc->customerTotal(
            subtotal: 100,
            deliveryCharge: 0,
            adjustments: [['type' => 'discount', 'amount' => 500]],
        );

        $this->assertSame(0.0, $total);
    }

    public function test_net_revenue_can_be_negative(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 500,
            deliveryCharge: 80,
            courierCharge: 120,
            adjustments: [['type' => 'discount', 'amount' => 100]],
            items: [['purchase_price' => 400, 'quantity' => 1]],
        );

        // 500 - 400 + 0 - 100 + 80 - 120 - packaging 0 = -40
        $this->assertSame(-40.0, $totals->netRevenue);
        $this->assertSame(0.0, $totals->packagingCost);
        $this->assertSame(-40.0, $totals->deliveryMargin + (500 - 400 - 100));
    }

    public function test_net_revenue_subtracts_packaging_cost(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 1000,
            deliveryCharge: 80,
            courierCharge: 60,
            adjustments: [],
            items: [['purchase_price' => 400, 'quantity' => 1]],
            packagingCost: 21,
        );

        // 1000 - 400 + 80 - 60 - 21 = 599
        $this->assertSame(21.0, $totals->packagingCost);
        $this->assertSame(599.0, $totals->netRevenue);
    }

    public function test_delivery_and_courier_are_independent(): void
    {
        $a = $this->calc->calculate(1000, 80, 50, [], []);
        $b = $this->calc->calculate(1000, 100, 50, [], []);
        $c = $this->calc->calculate(1000, 80, 70, [], []);

        $this->assertSame(80.0, $a->deliveryCharge);
        $this->assertSame(50.0, $a->courierCharge);
        $this->assertSame(100.0, $b->deliveryCharge);
        $this->assertSame(50.0, $b->courierCharge);
        $this->assertSame(80.0, $c->deliveryCharge);
        $this->assertSame(70.0, $c->courierCharge);
        $this->assertNotSame($a->netRevenue, $b->netRevenue);
        $this->assertNotSame($a->netRevenue, $c->netRevenue);
    }

    public function test_cogs_subtracts_returned_quantity(): void
    {
        $cogs = $this->calc->cogsFromItems([
            ['purchase_price' => 100, 'quantity' => 3, 'returned_quantity' => 1],
            ['purchase_price' => 50, 'quantity' => 2],
        ]);

        // 100*2 + 50*2 = 300
        $this->assertSame(300.0, $cogs);
    }

    public function test_net_revenue_formula(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 2000,
            deliveryCharge: 100,
            courierCharge: 60,
            adjustments: [
                ['type' => 'charge', 'amount' => 40],
                ['type' => 'coupon', 'amount' => 200],
            ],
            items: [['purchase_price' => 800, 'quantity' => 2]],
        );

        // revenue 2000 - cogs 1600 + charges 40 - discounts 200 + delivery 100 - courier 60 - pack 0 = 280
        $this->assertSame(1600.0, $totals->cogs);
        $this->assertSame(280.0, $totals->netRevenue);
        $this->assertSame(40.0, $totals->deliveryMargin);
        $this->assertSame(0.0, $totals->codCharge);
        $this->assertSame(1940.0, $totals->total); // 2000+100+40-200
    }

    public function test_steadfast_cod_charge_excludes_delivery(): void
    {
        $charge = $this->calc->codCharge(
            collectedAmount: 1180,
            deliveryCharge: 80,
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        // (1180 - 80) * 1% = 11
        $this->assertSame(11.0, $charge);
    }

    public function test_other_courier_cod_charge_uses_full_collected(): void
    {
        $charge = $this->calc->codCharge(
            collectedAmount: 1180,
            deliveryCharge: 80,
            courierSlug: 'pathao',
            codPercentage: 1,
        );

        // 1180 * 1% = 11.8
        $this->assertSame(11.8, $charge);
    }

    public function test_cod_charge_is_zero_without_collection(): void
    {
        $this->assertSame(0.0, $this->calc->codCharge(0, 80, 'steadfast', 1));
        $this->assertSame(0.0, $this->calc->codCharge(500, 80, 'steadfast', 0));
    }

    public function test_net_revenue_subtracts_cod_charge(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 2000,
            deliveryCharge: 100,
            courierCharge: 60,
            adjustments: [],
            items: [['purchase_price' => 800, 'quantity' => 2]],
            collectedAmount: 2100,
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        // base 2000 - 1600 + 100 - 60 = 440; COD = (2100 - 100) * 1% = 20; net = 420
        $this->assertSame(20.0, $totals->codCharge);
        $this->assertSame(420.0, $totals->netRevenue);
    }
}
