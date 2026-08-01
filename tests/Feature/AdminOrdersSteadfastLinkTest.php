<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersSteadfastLinkTest extends TestCase
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

    private function pathao(): Courier
    {
        return Courier::query()->create([
            'name' => 'Pathao',
            'slug' => 'pathao',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(Courier $courier, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'SF-'.uniqid(),
            'name' => 'Steadfast Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF123456',
            'courier_consignment_id' => '277193413',
            'dispatch_date' => now(),
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));
    }

    #[Test]
    public function dispatched_orders_list_links_to_steadfast_consignment(): void
    {
        $this->actingAs($this->adminUser());
        $this->order($this->steadfast());

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->assertSee('Steadfast ↗')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/277193413"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('title="Open Steadfast consignment"');
    }

    #[Test]
    public function delivered_orders_list_links_to_steadfast_consignment(): void
    {
        $this->actingAs($this->adminUser());
        $this->order($this->steadfast(), [
            'status' => 'delivered',
            'actual_delivery_date' => now(),
            'collected_amount' => 1080,
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'delivered'])
            ->assertSee('Steadfast ↗')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/277193413"')
            ->assertSee('SF123456');
    }

    #[Test]
    public function non_steadfast_dispatched_orders_do_not_get_steadfast_link(): void
    {
        $this->actingAs($this->adminUser());
        $this->order($this->pathao(), [
            'courier_consignment_id' => '999888777',
            'courier_tracker' => 'PX123',
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->assertSee('Pathao')
            ->assertDontSee('Pathao ↗')
            ->assertDontSeeHtml('href="https://steadfast.com.bd/user/consignment/999888777"');
    }

    #[Test]
    public function steadfast_link_requires_numeric_consignment_id(): void
    {
        $this->actingAs($this->adminUser());
        $this->order($this->steadfast(), [
            'courier_consignment_id' => null,
            'courier_tracker' => 'SF123456',
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->assertSee('Steadfast')
            ->assertDontSee('Steadfast ↗')
            ->assertDontSeeHtml('steadfast.com.bd/user/consignment/');
    }

    #[Test]
    public function order_show_links_to_steadfast_consignment_after_dispatch(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->order($this->steadfast());

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Steadfast ↗')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/277193413"')
            ->assertSeeHtml('target="_blank"')
            ->assertSee('SF123456');
    }
}
