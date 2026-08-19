<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersPlacedByCustomerTest extends TestCase
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
    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ORD-'.uniqid(),
            'name' => 'Store Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));
    }

    #[Test]
    public function customer_placed_orders_use_bold_gold_label_on_orders_list(): void
    {
        $this->actingAs($this->adminUser());
        $this->order();

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSeeHtml('font-semibold text-[#C9A227]')
            ->assertSee('Placed by Customer');
    }

    #[Test]
    public function staff_placed_orders_keep_muted_label_on_orders_list(): void
    {
        $this->actingAs($this->adminUser());

        $staff = User::factory()->create(['name' => 'Staff Member']);
        $this->order([
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'created_by' => $staff->id,
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSeeHtml('text-[#8C8474]')
            ->assertSee('Placed by Staff Member')
            ->assertDontSee('Placed by Customer');
    }
}
