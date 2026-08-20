<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUsers;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersOrderCountFilterTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function customer(string $name, string $phone): User
    {
        Role::findOrCreate('customers');
        $user = User::factory()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
        $user->assignRole('customers');

        return $user;
    }

    private function orderFor(User $customer, string $number, string $status = 'new'): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => $status,
            'placed_at' => now(),
        ]);
    }

    #[Test]
    public function customers_list_shows_lifetime_order_counts(): void
    {
        $this->actingAs($this->adminUser());

        $zero = $this->customer('Zero Orders', '01710000001');
        $one = $this->customer('One Order', '01710000002');
        $this->orderFor($one, 'OC-1');
        $this->orderFor($one, 'OC-draft', Order::STATUS_DRAFT); // excluded from count

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->assertDontSee('Merge duplicate phones')
            ->assertSee('Orders')
            ->assertSee('Lifetime orders from')
            ->assertSee('Zero Orders')
            ->assertSee('One Order')
            ->assertSeeHtml('>0</td>')
            ->assertSeeHtml('>1</td>');
    }

    #[Test]
    public function customers_list_filters_by_lifetime_order_range(): void
    {
        $this->actingAs($this->adminUser());

        $zero = $this->customer('No Orders Yet', '01710000011');
        $low = $this->customer('Low Orders', '01710000012');
        $high = $this->customer('High Orders', '01710000013');

        $this->orderFor($low, 'F-1');
        for ($i = 1; $i <= 5; $i++) {
            $this->orderFor($high, 'F-H-'.$i);
        }

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->set('ordersMin', '0')
            ->set('ordersMax', '1')
            ->assertSee('No Orders Yet')
            ->assertSee('Low Orders')
            ->assertDontSee('High Orders')
            ->set('ordersMin', '5')
            ->set('ordersMax', '10')
            ->assertDontSee('No Orders Yet')
            ->assertDontSee('Low Orders')
            ->assertSee('High Orders');
    }
}
