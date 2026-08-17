<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
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

class AdminOrdersCancelAndReturnWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function cancel_and_return_writes_off_merchandise_and_delivery_and_debits_courier_fee(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $courier->update(['balance' => 1080]);

        $order = Order::query()->create([
            'order_number' => 'CR-'.uniqid(),
            'name' => 'C/R Customer',
            'phone' => '01710000041',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'collected_amount' => 0,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Gold ring',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'dispatch',
            'amount' => 1080,
            'balance_after' => 1080,
            'order_id' => $order->id,
            'note' => 'Dispatch #'.$order->order_number,
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('cancelAndReturn', $order->id);

        $order->refresh()->load(['items', 'adjustments', 'courier']);
        $courier->refresh();

        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertSame(1, (int) $order->items->first()->returned_quantity);
        $this->assertSame(0.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(0.0, (float) $order->due_amount);

        $writeOff = OrderAdjustment::query()
            ->where('order_id', $order->id)
            ->where('source', 'partial_return_writeoff')
            ->first();
        $this->assertNotNull($writeOff);
        $this->assertEquals(1080.0, (float) $writeOff->amount);

        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );

        $this->assertSame(-60.0, (float) $courier->balance);
        $this->assertTrue(
            CourierBalanceEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'fee')
                ->where('amount', -60)
                ->exists()
        );
    }
}
