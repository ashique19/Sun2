<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersNetRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function moderatorUser(): User
    {
        Role::findOrCreate('moderator');

        $user = User::factory()->create();
        $user->assignRole('moderator');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function orderWithEconomics(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'ORD-'.uniqid(),
            'name' => 'Net Revenue Customer',
            'phone' => '01710000099',
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

        return $order;
    }

    #[Test]
    public function admin_orders_list_shows_cod_total_and_net_revenue(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithEconomics(['order_number' => 'NR-9001']);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSee('#NR-9001')
            ->assertSee('COD')
            ->assertSee('600')
            ->assertSee('Net')
            ->assertSee('335')
            ->assertSee('Breakdown');
    }

    #[Test]
    public function admin_orders_list_breakdown_includes_revenue_cogs_and_delivery_lines(): void
    {
        $this->actingAs($this->adminUser());
        $this->orderWithEconomics(['order_number' => 'NR-9002']);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSee('Revenue')
            ->assertSee('COGS')
            ->assertSee('Gift wrap')
            ->assertSee('Staff discount')
            ->assertSee('Cust. delivery')
            ->assertSee('Courier cost');
    }

    #[Test]
    public function negative_net_revenue_is_shown_on_orders_list(): void
    {
        $this->actingAs($this->adminUser());

        $order = $this->orderWithEconomics([
            'order_number' => 'NR-LOSS',
            'subtotal' => 100,
            'delivery_charge' => 0,
            'charge' => 0,
            'discount' => 0,
            'total' => 100,
            'courier_charge' => 200,
            'cod_amount' => 100,
            'due_amount' => 100,
        ]);

        OrderProduct::query()->where('order_id', $order->id)->update([
            'price' => 100,
            'purchase_price' => 150,
            'unit_cost' => 150,
            'line_total' => 100,
        ]);

        OrderAdjustment::query()->where('order_id', $order->id)->delete();

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSee('#NR-LOSS')
            ->assertSee('Net')
            ->assertSee('-250');
    }

    #[Test]
    public function moderator_orders_list_shows_net_revenue_breakdown(): void
    {
        $this->actingAs($this->moderatorUser());
        $this->orderWithEconomics(['order_number' => 'NR-MOD', 'status' => 'new']);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSee('Net Revenue Customer')
            ->assertSee('Net')
            ->assertSee('335')
            ->assertSee('Breakdown')
            ->assertSee('Courier cost');
    }
}
