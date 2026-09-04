<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Services\Admin\OrderDeliveryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExchangeOrderEconomicsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sept-1 original: value 1000, COD collected 1000, COGS 400, courier+other 150 → profit 550.
     * Sept-4 free replacement: due 0, courier+other 150 → net loss 150 only.
     * Linking must not rewrite the original sale.
     *
     * @return array{0: Order, 1: Order}
     */
    private function userScenarioPair(): array
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
            'status' => 'delivered',
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
            'actual_delivery_date' => now(),
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
        [$original, $replacement] = $this->userScenarioPair();

        $this->assertTrue($original->has_return);
        $this->assertSame(0, (int) $original->items->first()->returned_quantity);
        $this->assertEquals(1000.0, (float) $original->total);
        $this->assertEquals(1000.0, (float) $original->collected_amount);
        $this->assertEquals(0, OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->count());

        // COD fee 1% of 1000 = 10 → net = 1000 - 400 - 100 - 50 - 10 = 440
        // User's "profit 550" ignores COD %; assert COGS/fees keep sale intact.
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
}
