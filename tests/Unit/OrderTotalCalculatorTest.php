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
            codPercentage: 0,
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
            codPercentage: 0,
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

    public function test_cogs_prefers_unit_cost_and_falls_back_to_purchase_price(): void
    {
        $cogs = $this->calc->cogsFromItems([
            ['unit_cost' => 140, 'purchase_price' => 100, 'quantity' => 1],
            ['purchase_price' => 50, 'quantity' => 2],
        ]);

        // 140 + 50*2 = 240
        $this->assertSame(240.0, $cogs);
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
            codPercentage: 0,
        );

        // revenue 2000 - cogs 1600 + charges 40 - discounts 200 + delivery 100 - courier 60 - pack 0 = 280
        $this->assertSame(1600.0, $totals->cogs);
        $this->assertSame(280.0, $totals->netRevenue);
        $this->assertSame(40.0, $totals->deliveryMargin);
        $this->assertSame(0.0, $totals->codCharge);
        $this->assertSame(1940.0, $totals->total); // 2000+100+40-200
    }

    public function test_steadfast_cod_charge_excludes_their_delivery_fee(): void
    {
        $charge = $this->calc->codCharge(
            collectedAmount: 1180,
            courierDeliveryFee: 80,
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        // (1180 - 80) * 1% = 11
        $this->assertSame(11.0, $charge);
    }

    public function test_steadfast_total_charge_is_their_fee_plus_one_percent_of_remainder(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 1065,
            deliveryCharge: 135,
            courierCharge: 135,
            adjustments: [],
            items: [['purchase_price' => 400, 'quantity' => 1]],
            collectedAmount: 1200,
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        // Their fee 135 + (1200 − 135) × 1% = 135 + 10.65 = 145.65
        $this->assertSame(10.65, $totals->codCharge);
        $this->assertSame(1054.35, $totals->courierReceivable);
        $this->assertSame(145.65, round($totals->courierCharge + $totals->codCharge, 2));
    }

    public function test_other_courier_cod_charge_uses_full_collected(): void
    {
        $charge = $this->calc->codCharge(
            collectedAmount: 1180,
            courierDeliveryFee: 80,
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

        // COD = (2100 − 60 courier fee) × 1% = 20.4
        // net = 2000 − 1600 + 100 − 60 − 20.4 = 419.6
        $this->assertSame(20.4, $totals->codCharge);
        $this->assertSame(419.6, $totals->netRevenue);
        $this->assertSame(2100.0, $totals->remittanceBase);
        $this->assertSame(2019.6, $totals->courierReceivable); // 2100 - 60 - 20.4
        $this->assertSame(419.6, $totals->grossProfit); // 2019.6 - 1600 - 0
    }

    public function test_bill_receivable_gross_profit_cascade_for_unpaid_cod(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 2200,
            deliveryCharge: 120,
            courierCharge: 0,
            adjustments: [],
            items: [['purchase_price' => 700, 'quantity' => 2]],
            packagingCost: 0,
            expectedCodRemittance: 2320,
            codPercentage: 0,
        );

        // Unpaid COD remittance is passed explicitly — bill is not assumed to be courier cash.
        // Receivable = 2320 - 0 - 0 = 2320
        // Gross profit = 2320 - 1400 - 0 = 920
        $this->assertSame(2320.0, $totals->billToCustomer);
        $this->assertSame(2320.0, $totals->remittanceBase);
        $this->assertSame(2320.0, $totals->courierReceivable);
        $this->assertSame(1400.0, $totals->cogs);
        $this->assertSame(920.0, $totals->grossProfit);
        $this->assertSame(920.0, $totals->netRevenue);
    }

    public function test_prepaid_expected_cod_remittance_can_be_zero(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 1000,
            deliveryCharge: 80,
            courierCharge: 60,
            adjustments: [],
            items: [['purchase_price' => 400, 'quantity' => 1]],
            packagingCost: 21,
            expectedCodRemittance: 0,
        );

        $this->assertSame(0.0, $totals->remittanceBase);
        $this->assertSame(-60.0, $totals->courierReceivable);
        $this->assertSame(0.0, $totals->inHouseReceived);
        $this->assertSame(-481.0, $totals->grossProfit); // -60 - 400 - 21
    }

    public function test_prepaid_in_house_received_counts_toward_gross_profit(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 3130,
            deliveryCharge: 0,
            courierCharge: 0,
            adjustments: [],
            items: [
                ['purchase_price' => 750, 'quantity' => 1],
                ['purchase_price' => 700, 'quantity' => 1],
            ],
            expectedCodRemittance: 0,
            inHouseReceived: 3130,
        );

        // Prepaid bKash is in-house cash, not courier remittance.
        // GP = 3130 + 0 − 1450 − 0 = 1680 (same as net revenue).
        $this->assertSame(0.0, $totals->remittanceBase);
        $this->assertSame(0.0, $totals->courierReceivable);
        $this->assertSame(3130.0, $totals->inHouseReceived);
        $this->assertSame(1450.0, $totals->cogs);
        $this->assertSame(1680.0, $totals->grossProfit);
        $this->assertSame(1680.0, $totals->netRevenue);
    }

    public function test_unpaid_bill_is_not_courier_remittance_by_default(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 2200,
            deliveryCharge: 120,
            courierCharge: 60,
            adjustments: [],
            items: [['purchase_price' => 700, 'quantity' => 2]],
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        $this->assertSame(2320.0, $totals->billToCustomer);
        $this->assertSame(0.0, $totals->remittanceBase);
        $this->assertSame(0.0, $totals->codCharge);
        $this->assertSame(-60.0, $totals->courierReceivable);
    }

    public function test_delivery_fee_applies_even_when_money_is_in_house(): void
    {
        $totals = $this->calc->calculate(
            subtotal: 3130,
            deliveryCharge: 0,
            courierCharge: 80,
            adjustments: [],
            items: [['purchase_price' => 1450, 'quantity' => 1]],
            expectedCodRemittance: 0,
            inHouseReceived: 3130,
            courierSlug: 'steadfast',
            codPercentage: 1,
        );

        $this->assertSame(0.0, $totals->remittanceBase);
        $this->assertSame(0.0, $totals->codCharge);
        $this->assertSame(-80.0, $totals->courierReceivable);
        $this->assertSame(3130.0, $totals->inHouseReceived);
        $this->assertSame(1600.0, $totals->grossProfit); // 3130 − 80 − 1450
    }
}
