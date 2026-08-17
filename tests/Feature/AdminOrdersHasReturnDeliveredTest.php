<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Models\User;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersHasReturnDeliveredTest extends TestCase
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
     * @return array{0: Order, 1: OrderProduct, 2: OrderProduct}
     */
    private function deliveredOrderWithItems(): array
    {
        $order = Order::query()->create([
            'order_number' => 'DL-'.uniqid(),
            'name' => 'Delivered Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 2200,
            'delivery_charge' => 80,
            'total' => 2280,
            'collected_amount' => 2280,
            'paid_amount' => 2280,
            'due_amount' => 0,
            'has_return' => false,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);

        $kept = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Kept item',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1100,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1100,
        ]);

        $returned = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Returned item',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1100,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1100,
        ]);

        return [$order, $kept, $returned];
    }

    #[Test]
    public function delivered_hr_requires_returned_qty_then_writes_off_and_moves_to_pending(): void
    {
        $this->actingAs($this->adminUser());
        [$order, $kept, $returned] = $this->deliveredOrderWithItems();

        Livewire::test(AdminOrders::class, ['segment' => 'delivered'])
            ->assertSeeHtml('wire:click="toggleHasReturn('.$order->id.')"')
            ->assertSee('H/R')
            ->call('toggleHasReturn', $order->id)
            ->assertSet('showPartialModal', true)
            ->assertSet('partialMode', 'delivered')
            ->assertSee('Flag return (H/R)')
            ->set('partialReturns.'.$kept->id, 0)
            ->set('partialReturns.'.$returned->id, 1)
            ->call('submitPartialReturn')
            ->assertSet('showPartialModal', false)
            ->assertDontSeeHtml('wire:click="toggleHasReturn('.$order->id.')"');

        $order->refresh()->load(['items', 'adjustments']);
        $this->assertTrue($order->has_return);
        $this->assertSame('delivered', $order->status);
        $this->assertSame(0, (int) $order->items->firstWhere('id', $kept->id)->returned_quantity);
        $this->assertSame(1, (int) $order->items->firstWhere('id', $returned->id)->returned_quantity);
        $this->assertEquals(1180.0, (float) $order->total);

        $writeOff = OrderAdjustment::query()
            ->where('order_id', $order->id)
            ->where('source', 'partial_return_writeoff')
            ->first();
        $this->assertNotNull($writeOff);
        $this->assertEquals(1100.0, (float) $writeOff->amount);

        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }

    #[Test]
    public function delivered_hr_without_returned_qty_stays_on_delivered(): void
    {
        $this->actingAs($this->adminUser());
        [$order, $kept, $returned] = $this->deliveredOrderWithItems();

        Livewire::test(AdminOrders::class, ['segment' => 'delivered'])
            ->call('toggleHasReturn', $order->id)
            ->set('partialReturns.'.$kept->id, 0)
            ->set('partialReturns.'.$returned->id, 0)
            ->call('submitPartialReturn')
            ->assertHasErrors('partialReturns');

        $order->refresh();
        $this->assertFalse((bool) $order->has_return);
        $this->assertSame('delivered', $order->status);
    }
}
