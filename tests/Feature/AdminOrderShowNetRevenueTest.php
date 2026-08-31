<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderAdjustmentLog;
use App\Models\OrderProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderShowNetRevenueTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function orderWithEconomics(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'SHOW-'.uniqid(),
            'name' => 'Show Net Revenue',
            'phone' => '01710000088',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'charge' => 50,
            'discount' => 30,
            'total' => 600,
            'courier_charge' => 65,
            'cod_amount' => 600,
            'due_amount' => 600,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ], $overrides));

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Test Product',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        OrderAdjustment::query()->create([
            'order_id' => $order->id,
            'type' => 'charge',
            'label' => 'Gift wrap',
            'amount' => 50,
            'sort_order' => 0,
            'source' => 'admin',
        ]);

        OrderAdjustment::query()->create([
            'order_id' => $order->id,
            'type' => 'discount',
            'label' => 'Staff discount',
            'amount' => 30,
            'sort_order' => 1,
            'source' => 'admin',
        ]);

        OrderAdjustmentLog::query()->create([
            'order_id' => $order->id,
            'field' => 'courier_charge',
            'action' => 'updated',
            'phase' => 'dispatch',
            'amount_before' => 0,
            'amount_after' => 65,
            'created_at' => now(),
        ]);

        return $order->fresh(['items', 'adjustments', 'adjustmentLogs']);
    }

    #[Test]
    public function order_show_displays_net_revenue_breakdown_and_cod_total(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithEconomics(['order_number' => 'SHOW-NR-001']);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Bill to customer')
            ->assertSee('Amount to collect')
            ->assertSee('600')
            ->assertSee('Net revenue')
            ->assertSee('Revenue')
            ->assertSee('COGS')
            ->assertSee('Gift wrap')
            ->assertSee('Staff discount')
            ->assertSee('Customer delivery')
            ->assertSee('Courier cost')
            ->assertSee('(dispatch)')
            ->assertSee('Delivery margin')
            ->assertSee('335');
    }

    #[Test]
    public function order_show_displays_per_line_cogs_on_items(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithEconomics(['order_number' => 'SHOW-COGS']);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Test Product')
            ->assertSee('COGS')
            ->assertSee('200');
    }

    #[Test]
    public function negative_net_revenue_is_shown_on_order_show(): void
    {
        $this->actingAs($this->adminUser());

        $order = $this->orderWithEconomics([
            'order_number' => 'SHOW-LOSS',
            'subtotal' => 100,
            'delivery_charge' => 0,
            'charge' => 0,
            'discount' => 0,
            'total' => 100,
            'courier_charge' => 200,
        ]);

        OrderProduct::query()->where('order_id', $order->id)->update([
            'price' => 100,
            'purchase_price' => 150,
            'unit_cost' => 150,
            'line_total' => 100,
        ]);

        OrderAdjustment::query()->where('order_id', $order->id)->delete();

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh(['items', 'adjustments'])])
            ->assertSee('Net revenue')
            ->assertSee('-250');
    }
}
