<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardMonthAveragesTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(string $number, Carbon $placedAt, string $status, float $total): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'name' => 'Customer',
            'phone' => '0171'.random_int(1000000, 9999999),
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => $status,
            'subtotal' => $total,
            'delivery_charge' => 0,
            'total' => $total,
            'collected_amount' => $status === 'delivered' ? $total : 0,
            'paid_amount' => $status === 'delivered' ? $total : 0,
            'placed_at' => $placedAt->copy()->utc(),
            'actual_delivery_date' => $status === 'delivered' ? $placedAt->copy()->utc() : null,
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);
    }

    #[Test]
    public function this_month_averages_divide_by_day_of_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 15:00:00', 'Asia/Dhaka'));

        // 40 orders · ৳40,000 ordered; 20 delivered · ৳20,000 → /20 days
        for ($i = 0; $i < 40; $i++) {
            $this->order(
                'AVG-O-'.$i,
                Carbon::parse('2026-08-'.sprintf('%02d', ($i % 20) + 1).' 10:00:00', 'Asia/Dhaka'),
                $i < 20 ? 'delivered' : 'new',
                1000,
            );
        }

        $activity = AdminDashboardMetrics::orderActivity(fresh: true);
        $thisMonth = $activity['months'][0];

        $this->assertSame('This month', $thisMonth['label']);
        $this->assertSame(20, $thisMonth['day_count']);
        $this->assertSame(40, $thisMonth['totals']['order_qty']);
        $this->assertSame(20, $thisMonth['totals']['delivery_qty']);
        $this->assertSame(2.0, $thisMonth['averages']['order_qty']);
        $this->assertSame(2000.0, $thisMonth['averages']['order_value']);
        $this->assertSame(1.0, $thisMonth['averages']['delivery_qty']);
        $this->assertSame(1000.0, $thisMonth['averages']['delivery_value']);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertSee('avg 2.0')
            ->assertSee('৳2,000/day')
            ->assertSee('avg 1.0')
            ->assertSee('৳1,000/day');

        Carbon::setTestNow();
    }

    #[Test]
    public function last_month_averages_divide_by_days_in_that_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 15:00:00', 'Asia/Dhaka'));

        // July has 31 days. 31 orders · ৳31,000 → avg 1.0 · ৳1,000/day
        for ($day = 1; $day <= 31; $day++) {
            $this->order(
                'AVG-L-'.$day,
                Carbon::parse('2026-07-'.sprintf('%02d', $day).' 10:00:00', 'Asia/Dhaka'),
                'delivered',
                1000,
            );
        }

        $activity = AdminDashboardMetrics::orderActivity(fresh: true);
        $lastMonth = $activity['months'][1];

        $this->assertSame('Last month', $lastMonth['label']);
        $this->assertSame(31, $lastMonth['day_count']);
        $this->assertSame(31, $lastMonth['totals']['order_qty']);
        $this->assertSame(1.0, $lastMonth['averages']['order_qty']);
        $this->assertSame(1000.0, $lastMonth['averages']['order_value']);
        $this->assertSame(1.0, $lastMonth['averages']['delivery_qty']);
        $this->assertSame(1000.0, $lastMonth['averages']['delivery_value']);

        Carbon::setTestNow();
    }
}
