<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Admin\OrderDeliveryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExchangePairEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Order, 1: Order}
     */
    private function linkedPair(): array
    {
        $original = Order::query()->create([
            'order_number' => 'PAIR-ORIG',
            'name' => 'Pair Customer',
            'phone' => '01710000071',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1100,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'total' => 1180,
            'collected_amount' => 1180,
            'paid_amount' => 1180,
            'due_amount' => 0,
            'has_return' => false,
            'placed_at' => now()->subDays(2),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $original->id,
            'method' => 'cod',
            'amount' => 1180,
            'status' => 'completed',
            'kind' => 'settlement',
            'paid_at' => now()->subDay(),
            'external_id' => 'cod:settlement:order:'.$original->id,
        ]);

        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Kurti',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1100,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1100,
        ]);

        $replacement = Order::query()->create([
            'order_number' => 'PAIR-EXC',
            'name' => 'Pair Customer',
            'phone' => '01710000071',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'delivered',
            'subtotal' => 0,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'total' => 80,
            'collected_amount' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now()->subDay(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'name' => 'Kurti',
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
    public function pair_economics_sum_independent_order_totals_without_write_off(): void
    {
        [$original, $replacement] = $this->linkedPair();

        $this->assertEquals(1180.0, (float) $original->total);
        $this->assertSame(0, (int) $original->items->first()->returned_quantity);
        $this->assertEquals(0, OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->count());

        $pair = $original->exchangePairEconomics();
        $this->assertNotNull($pair);
        $this->assertCount(2, $pair['orders']);
        $this->assertEquals(1180.0, $pair['collected']);
        $this->assertEquals(0.0, $pair['write_off']);
        // Original merchandise COGS only — free replacement contributes 0.
        $this->assertEquals(400.0, $pair['cogs']);
        $this->assertEquals(42.0, $pair['packaging']);
        $this->assertEquals(120.0, $pair['courier']);

        $expectedGross = $original->moneyTotals()->grossProfit
            + $replacement->moneyTotals()->grossProfit;
        $this->assertEquals($expectedGross, $pair['gross_profit']);

        $fromReplacement = $replacement->exchangePairEconomics();
        $this->assertNotNull($fromReplacement);
        $this->assertEquals($pair['gross_profit'], $fromReplacement['gross_profit']);
    }

    #[Test]
    public function unlinked_order_has_no_pair_economics(): void
    {
        $order = Order::query()->create([
            'order_number' => 'SOLO',
            'name' => 'Solo',
            'phone' => '01710000072',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'placed_at' => now(),
        ]);

        $this->assertNull($order->exchangePairEconomics());
    }

    #[Test]
    public function order_show_renders_exchange_pair_pl(): void
    {
        $this->actingAs($this->adminUser());
        [$original] = $this->linkedPair();

        Livewire::test(AdminOrderShow::class, ['order' => $original])
            ->assertSee('Exchange pair P/L')
            ->assertSee('PAIR-ORIG')
            ->assertSee('PAIR-EXC')
            ->assertSee('Original sale kept')
            ->assertDontSee('Returned write-off');
    }
}
