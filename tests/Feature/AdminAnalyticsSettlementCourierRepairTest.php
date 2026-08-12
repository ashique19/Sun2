<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Admin\OrderSettlementCourierRepairService;
use App\Services\Orders\OrderCourierChargeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsSettlementCourierRepairTest extends TestCase
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
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function modal_repairs_zero_courier_and_settlement_in_batches(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $zeroCourier = Order::query()->create([
            'order_number' => 'ZERO-COUR',
            'name' => 'Zero Courier',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'total' => 580,
            'courier_charge' => 0,
            'packaging_cost' => 21,
            'collected_amount' => 580,
            'paid_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'partial',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(3),
        ]);
        OrderProduct::query()->create([
            'order_id' => $zeroCourier->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        $returned = Order::query()->create([
            'order_number' => 'RET-COUR',
            'name' => 'Returned',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 400,
            'total' => 400,
            'courier_charge' => 0,
            'courier_id' => $courier->id,
            'placed_at' => now()->subDays(4),
        ]);
        OrderProduct::query()->create([
            'order_id' => $returned->id,
            'name' => 'Item',
            'quantity' => 1,
            'returned_quantity' => 1,
            'price' => 400,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'line_total' => 400,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertSee('Repair settlement…')
            ->call('openSettlementRepairModal')
            ->assertSet('settlementModalOpen', true)
            ->assertSet('settlementTotal', 2)
            ->call('startSettlementRepair')
            ->assertSet('settlementDone', true)
            ->assertSet('settlementCourierFixed', 2)
            ->assertSet('settlementSettlementFixed', 1);

        $zeroCourier->refresh();
        $this->assertSame(60.0, (float) $zeroCourier->courier_charge);
        $this->assertSame(0.0, (float) $zeroCourier->due_amount);
        $this->assertSame('paid', $zeroCourier->payment_status);
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $zeroCourier->id)->count());

        $returned->refresh();
        $this->assertSame(60.0, (float) $returned->courier_charge);
    }

    #[Test]
    public function courier_estimate_uses_shipped_qty_when_fully_returned(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'FULL-RET',
            'name' => 'Full return',
            'phone' => '01710000003',
            'address' => 'Gazipur',
            'city' => 'Gazipur',
            'status' => 'returned',
            'subtotal' => 800,
            'total' => 800,
            'courier_charge' => 0,
            'courier_id' => $courier->id,
            'placed_at' => now()->subDay(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'A',
            'quantity' => 2,
            'returned_quantity' => 2,
            'price' => 400,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'line_total' => 800,
        ]);

        $fee = app(OrderCourierChargeSync::class)->estimateMerchantDeliveryFee($order->fresh(['items', 'courier']));

        // Outside Dhaka Steadfast: 120 + 10 for second piece.
        $this->assertSame(130.0, $fee);
    }

    #[Test]
    public function service_skips_exchange_settlement_but_can_still_fill_courier(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'EXCH-COUR',
            'name' => 'Exchange',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'is_replacement' => true,
            'subtotal' => 500,
            'total' => 500,
            'courier_charge' => 0,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 500,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        $result = app(OrderSettlementCourierRepairService::class)->repairOrder($order->fresh());

        $this->assertTrue($result['courier_fixed']);
        $this->assertFalse($result['settlement_fixed']);
        $this->assertSame(60.0, (float) $order->fresh()->courier_charge);
        $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function fills_courier_when_order_has_no_courier_id_using_default(): void
    {
        $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'NO-COUR-ID',
            'name' => 'No courier',
            'phone' => '01710000005',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'total' => 580,
            'courier_charge' => 0,
            'courier_id' => null,
            'collected_amount' => 580,
            'paid_amount' => 580,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        $service = app(OrderSettlementCourierRepairService::class);

        $this->assertSame(1, $service->eligibleOrderCount());
        $result = $service->repairOrder($order->fresh());

        $this->assertTrue($result['courier_fixed']);
        $this->assertSame(60.0, (float) $order->fresh()->courier_charge);
        $this->assertNull($order->fresh()->courier_id);
    }

    #[Test]
    public function fills_one_piece_courier_when_delivered_has_no_line_qty(): void
    {
        $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'NO-LINES',
            'name' => 'Empty lines',
            'phone' => '01710000006',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 0,
            'total' => 0,
            'courier_charge' => 0,
            'courier_id' => null,
            'placed_at' => now()->subDay(),
        ]);

        $result = app(OrderSettlementCourierRepairService::class)->repairOrder($order->fresh());

        $this->assertTrue($result['courier_fixed']);
        $this->assertSame(60.0, (float) $order->fresh()->courier_charge);
    }
}
