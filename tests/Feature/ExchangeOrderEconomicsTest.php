<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExchangeOrderEconomicsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sept-1 original: value 1000, COD collected 1000, COGS 400, courier+other 150.
     * Sept-4 free replacement: due 0, courier+other 150 → net loss 150 only.
     *
     * @return array{0: Order, 1: Order}
     */
    private function userScenarioPair(string $replacementStatus = 'new'): array
    {
        $original = Order::query()->create([
            'order_number' => 'SEP1-ORIG',
            'name' => 'COD Customer',
            'phone' => '01710000991',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 0,
            'courier_charge' => 100,
            'packaging_cost' => 50,
            'total' => 1000,
            'collected_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'has_return' => false,
            'placed_at' => now()->subDays(3),
            'actual_delivery_date' => now()->subDays(3),
        ]);

        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Dress',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

        $replacement = Order::query()->create([
            'order_number' => 'SEP4-EXC',
            'name' => 'COD Customer',
            'phone' => '01710000991',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => $replacementStatus,
            'subtotal' => 0,
            'delivery_charge' => 0,
            'courier_charge' => 100,
            'packaging_cost' => 50,
            'total' => 0,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'has_return' => false,
            'placed_at' => now(),
            'actual_delivery_date' => $replacementStatus === 'delivered' ? now() : null,
            'dispatch_date' => in_array($replacementStatus, ['dispatched', 'delivered'], true) ? now() : null,
        ]);

        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'name' => 'Dress',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 0,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(['items']),
            $replacement->fresh(['items']),
        );

        return [$original->fresh(['items', 'adjustments']), $replacement->fresh(['items'])];
    }

    #[Test]
    public function linking_exchange_keeps_original_cod_sale_and_isolates_replacement_loss(): void
    {
        [$original, $replacement] = $this->userScenarioPair('new');

        $this->assertFalse($original->has_return);
        $this->assertSame(0, (int) $original->items->first()->returned_quantity);
        $this->assertEquals(1000.0, (float) $original->total);
        $this->assertEquals(1000.0, (float) $original->collected_amount);
        $this->assertEquals(0, OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->count());

        // COD fee 1% of 1000 = 10 → net = 1000 - 400 - 100 - 50 - 10 = 440
        $originalTotals = $original->moneyTotals();
        $this->assertEquals(400.0, $originalTotals->cogs);
        $this->assertEquals(1000.0, $originalTotals->billToCustomer);
        $this->assertEquals(440.0, $originalTotals->netRevenue);

        $this->assertTrue($replacement->isFreeExchangeReplacement());
        $replacementTotals = $replacement->moneyTotals();
        $this->assertEquals(0.0, $replacementTotals->cogs);
        $this->assertEquals(-150.0, $replacementTotals->netRevenue);
        $this->assertEquals(-150.0, $replacementTotals->grossProfit);

        $pair = $original->exchangePairEconomics();
        $this->assertNotNull($pair);
        $this->assertEquals(0.0, $pair['write_off']);
        $this->assertEquals(400.0, $pair['cogs']);
        $this->assertEquals(100.0, $pair['packaging']);
        $this->assertEquals(200.0, $pair['courier']);
        $this->assertEquals(440.0 - 150.0, $pair['net_revenue']);
    }

    #[Test]
    public function delivering_exchange_flags_original_for_return_parcel(): void
    {
        [$original, $replacement] = $this->userScenarioPair('dispatched');
        $this->assertFalse($original->has_return);

        app(OrderDeliveryReturnService::class)->markDelivered(
            $replacement->fresh(),
            collectedAmount: 0.0,
        );

        $this->assertTrue($original->fresh()->has_return);
        $this->assertEquals(1000.0, (float) $original->fresh()->total);
        $this->assertEquals(1000.0, (float) $original->fresh()->collected_amount);
        $this->assertSame(0, (int) $original->fresh(['items'])->items->first()->returned_quantity);
    }

    #[Test]
    public function linking_already_delivered_exchange_flags_original_return(): void
    {
        [$original] = $this->userScenarioPair('delivered');

        $this->assertTrue($original->has_return);
        $this->assertEquals(1000.0, (float) $original->total);
    }

    #[Test]
    public function exchange_with_addon_products_charges_and_discounts_is_independent(): void
    {
        $original = Order::query()->create([
            'order_number' => 'MIX-ORIG',
            'name' => 'Mix Customer',
            'phone' => '01710000992',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 0,
            'courier_charge' => 100,
            'packaging_cost' => 50,
            'total' => 1000,
            'collected_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'has_return' => false,
            'placed_at' => now()->subDay(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Dress',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

        // Free swap + billed add-on 500, charge 50, discount 20 → subtotal 500, total 530
        $replacement = Order::query()->create([
            'order_number' => 'MIX-EXC',
            'name' => 'Mix Customer',
            'phone' => '01710000992',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'charge' => 50,
            'discount' => 20,
            'courier_charge' => 100,
            'packaging_cost' => 50,
            'total' => 530,
            'collected_amount' => 530,
            'paid_amount' => 530,
            'due_amount' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now(),
        ]);
        OrderAdjustment::query()->create([
            'order_id' => $replacement->id,
            'type' => 'charge',
            'label' => 'Gift wrap',
            'amount' => 50,
            'source' => 'manual',
            'sort_order' => 1,
        ]);
        OrderAdjustment::query()->create([
            'order_id' => $replacement->id,
            'type' => 'discount',
            'label' => 'Courtesy',
            'amount' => 20,
            'source' => 'manual',
            'sort_order' => 2,
        ]);
        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'name' => 'Dress swap',
            'quantity' => 1,
            'price' => 0,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 0,
        ]);
        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'name' => 'Scarf add-on',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(['items']),
            $replacement->fresh(['items']),
        );

        $original->refresh();
        $this->assertTrue($original->has_return);
        $this->assertEquals(440.0, $original->moneyTotals()->netRevenue);

        $replacement = $replacement->fresh(['items', 'adjustments']);
        $totals = $replacement->moneyTotals();
        // Only billed scarf COGS 200 — free swap line excluded
        $this->assertEquals(200.0, $totals->cogs);
        $this->assertEquals(50.0, $totals->charges);
        $this->assertEquals(20.0, $totals->discounts);
        $this->assertEquals(530.0, $totals->billToCustomer);
        // net = 500 - 200 + 50 - 20 + 0 - 100 - 50 - COD(530*1%=5.3) = 174.7
        $this->assertEquals(174.7, $totals->netRevenue);

        // Original sale untouched by exchange money
        $this->assertEquals(1000.0, (float) $original->total);
        $this->assertEquals(0, OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->count());
    }

    #[Test]
    public function status_update_to_delivered_also_flags_original_return(): void
    {
        [$original, $replacement] = $this->userScenarioPair('dispatched');

        app(OrderStatusService::class)->update(
            $replacement->fresh(),
            'delivered',
            'Courier delivered exchange.',
        );

        $this->assertTrue($original->fresh()->has_return);
    }
}
