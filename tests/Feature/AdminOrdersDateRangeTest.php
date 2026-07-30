<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'OR-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000000',
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
    public function orders_list_filters_by_placed_at_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'Asia/Dhaka'));

        $this->actingAs($this->adminUser());

        $inRange = $this->order([
            'order_number' => 'IN-RANGE',
            'name' => 'Inside Range',
            'status' => 'new',
            'placed_at' => Carbon::parse('2026-07-15 10:00:00', 'Asia/Dhaka'),
        ]);
        $before = $this->order([
            'order_number' => 'BEFORE',
            'name' => 'Before Range',
            'status' => 'new',
            'placed_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Dhaka'),
        ]);
        $after = $this->order([
            'order_number' => 'AFTER',
            'name' => 'After Range',
            'status' => 'new',
            'placed_at' => Carbon::parse('2026-07-25 10:00:00', 'Asia/Dhaka'),
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->set('dateFrom', '2026-07-10')
            ->set('dateTo', '2026-07-20')
            ->assertSee('Inside Range')
            ->assertSee('IN-RANGE')
            ->assertDontSee('Before Range')
            ->assertDontSee('After Range')
            ->assertDontSee('BEFORE')
            ->assertDontSee('AFTER');

        $this->assertNotNull($inRange->fresh());
        $this->assertNotNull($before->fresh());
        $this->assertNotNull($after->fresh());

        Carbon::setTestNow();
    }

    #[Test]
    public function dispatched_list_filters_by_dispatch_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'Asia/Dhaka'));

        $this->actingAs($this->adminUser());

        $this->order([
            'order_number' => 'DISP-IN',
            'name' => 'Dispatched In',
            'status' => 'dispatched',
            'placed_at' => Carbon::parse('2026-07-01 10:00:00', 'Asia/Dhaka'),
            'dispatch_date' => Carbon::parse('2026-07-15 14:00:00', 'Asia/Dhaka'),
        ]);
        $this->order([
            'order_number' => 'DISP-OUT',
            'name' => 'Dispatched Out',
            'status' => 'dispatched',
            'placed_at' => Carbon::parse('2026-07-14 10:00:00', 'Asia/Dhaka'),
            'dispatch_date' => Carbon::parse('2026-07-05 14:00:00', 'Asia/Dhaka'),
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->set('dateFrom', '2026-07-10')
            ->set('dateTo', '2026-07-20')
            ->assertSee('Dispatched In')
            ->assertSee('DISP-IN')
            ->assertDontSee('Dispatched Out')
            ->assertDontSee('DISP-OUT');

        Carbon::setTestNow();
    }

    #[Test]
    public function clear_date_range_removes_filter(): void
    {
        $this->actingAs($this->adminUser());

        $this->order([
            'order_number' => 'OLD-ONE',
            'name' => 'Old Order',
            'status' => 'new',
            'placed_at' => Carbon::parse('2026-01-01 10:00:00', 'Asia/Dhaka'),
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->set('dateFrom', '2026-07-01')
            ->set('dateTo', '2026-07-31')
            ->assertDontSee('Old Order')
            ->call('clearDateRange')
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '')
            ->assertSee('Old Order');
    }
}
