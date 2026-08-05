<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use App\Services\Admin\OrderDeliveryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersPartialReturnCollectedTest extends TestCase
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
    public function partial_return_records_rider_collected_and_writes_off_returned_items(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        // 2 × 1100 + delivery 120 = 2320 COD (webhook collected 1220).
        $order = Order::query()->create([
            'order_number' => 'PR-2320',
            'name' => 'Partial Customer',
            'phone' => '01710000888',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 2200,
            'delivery_charge' => 120,
            'courier_charge' => 60,
            'total' => 2320,
            'cod_amount' => 2320,
            'due_amount' => 2320,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        $kept = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Kept item',
            'quantity' => 1,
            'price' => 1100,
            'purchase_price' => 400,
            'line_total' => 1100,
        ]);
        $returned = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Returned item',
            'quantity' => 1,
            'price' => 1100,
            'purchase_price' => 400,
            'line_total' => 1100,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('openPartialReturn', $order->id)
            ->assertSet('partialCollectedTk', '2320')
            ->set('partialReturns.'.$returned->id, 1)
            ->set('partialReturns.'.$kept->id, 0)
            ->set('partialCollectedTk', '1220')
            ->call('submitPartialReturn');

        $order->refresh()->load(['courier', 'adjustments']);

        $this->assertSame('delivered', $order->status);
        $this->assertTrue((bool) $order->has_return);

        // Bill becomes kept item + delivery after return write-off.
        $this->assertSame(1220.0, (float) $order->total);
        $this->assertSame(1100.0, (float) $order->discount);
        $this->assertTrue(
            $order->adjustments->contains(
                fn (OrderAdjustment $line) => $line->source === OrderDeliveryReturnService::PARTIAL_RETURN_WRITEOFF_SOURCE
                    && (float) $line->amount === 1100.0
            )
        );

        // Rider-entered 1220 must be collected — not residual product-only 1100.
        $this->assertSame(1220.0, (float) $order->collected_amount);
        $this->assertSame(1220.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame(0.0, (float) $order->cod_amount);

        $this->assertSame(1220.0, (float) PaymentTransaction::query()->where('order_id', $order->id)->value('amount'));
        $this->assertSame(1220, (int) CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('note', 'like', 'Partial collect%')
            ->value('amount'));

        $money = $order->moneyTotals();
        $this->assertSame(1220.0, $money->remittanceBase);
        $this->assertSame(11.0, $money->codCharge); // (1220 - 120) × 1%
        $this->assertSame(1220.0 - 60.0 - 11.0, $money->courierReceivable);
    }

    #[Test]
    public function partial_return_does_not_subtract_courier_charge_from_collected(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'PR-GROSS',
            'name' => 'Gross Customer',
            'phone' => '01710000889',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        $item = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 2,
            'price' => 500,
            'purchase_price' => 200,
            'line_total' => 1000,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        // Return one unit (৳500); rider collected kept + delivery = 580.
        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('openPartialReturn', $order->id)
            ->set('partialReturns.'.$item->id, 1)
            ->set('partialCollectedTk', '580')
            ->call('submitPartialReturn');

        $order->refresh();

        $this->assertSame(580.0, (float) $order->total);
        $this->assertSame(580.0, (float) $order->collected_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertNotSame(520.0, (float) $order->collected_amount); // not 580 − 60 courier charge
    }
}
