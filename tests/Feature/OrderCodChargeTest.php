<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCodChargeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function makeOrder(Courier $courier, float $collected, float $delivery): Order
    {
        $order = Order::query()->create([
            'order_number' => 'COD-'.uniqid(),
            'name' => 'COD Customer',
            'phone' => '01700000000',
            'address' => 'Test address',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => $delivery,
            'courier_charge' => 60,
            'collected_amount' => $collected,
            'total' => 1000 + $delivery,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function steadfast_cod_charge_uses_collected_minus_delivery(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = $this->makeOrder($courier, collected: 1180, delivery: 80);

        $this->assertSame(11.0, $order->codCharge());
        // 1000 - 400 + 80 - 60 - 11 = 609
        $this->assertSame(609.0, $order->netRevenue());
    }

    #[Test]
    public function other_courier_cod_charge_uses_full_collected_amount(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Pathao',
            'slug' => 'pathao',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = $this->makeOrder($courier, collected: 1180, delivery: 80);

        $this->assertSame(11.8, $order->codCharge());
        // 1000 - 400 + 80 - 60 - 11.8 = 608.2
        $this->assertSame(608.2, $order->netRevenue());
    }

    #[Test]
    public function order_show_displays_cod_charge_breakdown(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $order = $this->makeOrder($courier, collected: 1180, delivery: 80);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('COD charge')
            ->assertSee('1% of collected − delivery')
            ->assertSee('11.00');
    }

    #[Test]
    public function courier_edit_explains_cod_percentage_formulas(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.couriers.create'))
            ->assertOk()
            ->assertSee('Steadfast: (collected − delivery) × %')
            ->assertSee('Other couriers: collected × %');
    }
}
