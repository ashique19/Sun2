<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderCancelReturnMoneyFlowTest extends TestCase
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
            'charge' => 105,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dispatchedOrder(Courier $courier, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'CR-MONEY-'.uniqid(),
            'name' => 'Money Flow Customer',
            'phone' => '01710000991',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 500,
            'delivery_charge' => 120,
            'courier_charge' => 105,
            'packaging_cost' => 21,
            'total' => 620,
            'cod_amount' => 620,
            'due_amount' => 620,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function cancel_and_return_with_no_cash_nets_negative_courier_plus_packaging(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('cancelAndReturn', $order->id);

        $order->refresh()->load(['items', 'adjustments', 'courier']);
        $money = $order->moneyTotals();

        $this->assertSame('returned', $order->status);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(-126.0, $money->netRevenue);
        $this->assertSame(0.0, $money->remittanceBase);
    }

    #[Test]
    public function all_items_returned_with_delivery_cash_nets_collected_minus_logistics(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier);

        $item = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('openPartialReturn', $order->id)
            ->set('partialReturns.'.$item->id, 1)
            ->set('partialCollectedTk', '120')
            ->call('submitPartialReturn');

        $order->refresh()->load(['items', 'adjustments', 'courier']);
        $money = $order->moneyTotals();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(120.0, (float) $order->collected_amount);
        $this->assertSame(120.0, (float) $order->total);
        // Steadfast COD % of (collected − delivery) = 0 when only delivery was collected.
        $this->assertSame(-6.0, $money->netRevenue);
        $this->assertSame(120.0, $money->remittanceBase);
    }

    #[Test]
    public function admin_order_show_exposes_settlement_actions_for_dispatched_orders(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, ['order_number' => 'SHOW-ACTIONS']);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh(['items', 'adjustments', 'courier'])])
            ->assertSee('Delivered')
            ->assertSee('Partial')
            ->assertSee('Cancel/Return')
            ->call('openPartialReturn')
            ->assertSet('showPartialModal', true);
    }

    #[Test]
    public function admin_order_show_cancel_and_return_uses_logistics_net(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, ['order_number' => 'SHOW-CANCEL']);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh(['items', 'adjustments', 'courier'])])
            ->call('cancelAndReturn')
            ->assertSee('After cancel / return')
            ->assertSee('-126');
    }
}
