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

class AdminDashboardMetricsTest extends TestCase
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
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Customer',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 1200,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1280,
            'cod_amount' => 1280,
            'due_amount' => 1280,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
            'collected_amount' => 0,
        ], $overrides));
    }

    /**
     * @param  list<array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}>  $days
     * @return array{date: string, label: string, order_qty: int, order_value: float, delivery_qty: int, delivery_value: float}|null
     */
    private function dayRow(array $days, string $date): ?array
    {
        foreach ($days as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        return null;
    }

    #[Test]
    public function delivered_metrics_count_orders_from_placement_day_cohort(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', 'Asia/Dhaka'));

        $july28 = Carbon::parse('2026-07-28 10:00:00', 'Asia/Dhaka');
        $july29 = Carbon::parse('2026-07-29 15:00:00', 'Asia/Dhaka');

        // 9 orders placed on Jul-28; 6 delivered later, 3 still open
        for ($i = 0; $i < 6; $i++) {
            $this->order([
                'placed_at' => $july28->copy()->addMinutes($i),
                'status' => 'delivered',
                'actual_delivery_date' => $july29,
                'collected_amount' => 1000,
                'total' => 1100,
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order([
                'placed_at' => $july28->copy()->addHours(1)->addMinutes($i),
                'status' => 'dispatched',
                'collected_amount' => 0,
                'total' => 900,
            ]);
        }

        // Delivered on Jul-29 but placed Jul-29 — must not inflate Jul-28 DQ
        $this->order([
            'placed_at' => $july29,
            'status' => 'delivered',
            'actual_delivery_date' => $july29,
            'collected_amount' => 500,
            'total' => 500,
        ]);

        $activity = AdminDashboardMetrics::orderActivity(fresh: true);
        $row = $this->dayRow($activity['months'][0]['days'], '2026-07-28');

        $this->assertNotNull($row);
        $this->assertSame(9, $row['order_qty']);
        $this->assertSame(6 * 1100.0 + 3 * 900.0, $row['order_value']);
        $this->assertSame(6, $row['delivery_qty']);
        $this->assertSame(6000.0, $row['delivery_value']);

        Carbon::setTestNow();
    }

    #[Test]
    public function order_activity_builds_month_tiles_and_last_seven_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Asia/Dhaka'));

        $today = Carbon::parse('2026-07-29 10:00:00', 'Asia/Dhaka');
        $yesterday = Carbon::parse('2026-07-28 11:00:00', 'Asia/Dhaka');
        $eightDaysAgo = Carbon::parse('2026-07-21 09:00:00', 'Asia/Dhaka');
        $previousMonthDay = Carbon::parse('2026-06-05 09:00:00', 'Asia/Dhaka');

        $this->order([
            'placed_at' => $today,
            'status' => 'delivered',
            'actual_delivery_date' => $today,
            'collected_amount' => 1200,
            'total' => 1590,
        ]);
        $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $today, // delivered next day — still Jul-28 cohort
            'collected_amount' => 300,
            'total' => 870,
        ]);
        $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $yesterday,
            'collected_amount' => 450,
            'total' => 560,
        ]);
        $this->order([
            'placed_at' => $today,
            'status' => 'new',
            'collected_amount' => 999,
            'total' => 500,
        ]);
        $this->order([
            'placed_at' => $today,
            'status' => Order::STATUS_DRAFT,
            'collected_amount' => 100,
            'total' => 100,
        ]);
        $this->order([
            'placed_at' => $eightDaysAgo,
            'status' => 'delivered',
            'actual_delivery_date' => $eightDaysAgo,
            'collected_amount' => 200,
            'total' => 250,
        ]);
        $this->order([
            'placed_at' => $previousMonthDay,
            'status' => 'delivered',
            'actual_delivery_date' => $previousMonthDay,
            'collected_amount' => 700,
            'total' => 800,
        ]);

        $activity = AdminDashboardMetrics::orderActivity(fresh: true);
        $months = $activity['months'];
        $last7 = $activity['last7'];

        $this->assertSame('This month', $months[0]['label']);
        $this->assertSame('Last month', $months[1]['label']);
        $this->assertCount(7, $last7['days']);
        $this->assertSame(4, $last7['totals']['order_qty']);
        $this->assertSame(3, $last7['totals']['delivery_qty']); // 1 today + 2 yesterday

        $todayRow = $this->dayRow($months[0]['days'], '2026-07-29');
        $yesterdayRow = $this->dayRow($months[0]['days'], '2026-07-28');
        $previousRow = $this->dayRow($months[1]['days'], '2026-06-05');

        $this->assertNotNull($todayRow);
        $this->assertSame(2, $todayRow['order_qty']);
        $this->assertSame(2090.0, $todayRow['order_value']);
        $this->assertSame(1, $todayRow['delivery_qty']); // one delivered order, not item pieces
        $this->assertSame(1200.0, $todayRow['delivery_value']);

        $this->assertNotNull($yesterdayRow);
        $this->assertSame(2, $yesterdayRow['order_qty']);
        $this->assertSame(1430.0, $yesterdayRow['order_value']);
        $this->assertSame(2, $yesterdayRow['delivery_qty']);
        $this->assertSame(750.0, $yesterdayRow['delivery_value']);

        $this->assertNotNull($previousRow);
        $this->assertSame(1, $previousRow['order_qty']);
        $this->assertSame(1, $previousRow['delivery_qty']);
        $this->assertSame(700.0, $previousRow['delivery_value']);

        Carbon::setTestNow();
    }

    #[Test]
    public function dashboard_defaults_to_last_seven_days_and_opens_month_on_tile_click(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Asia/Dhaka'));
        $this->actingAs($this->adminUser());

        $this->order([
            'placed_at' => now('Asia/Dhaka')->subDays(2),
            'status' => 'new',
            'total' => 500,
        ]);
        $this->order([
            'placed_at' => now('Asia/Dhaka')->subMonthNoOverflow()->startOfMonth()->addDays(3),
            'status' => 'new',
            'total' => 700,
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertSet('ordersDateRange', 'last7')
            ->assertSee('This month')
            ->assertSee('Last month')
            ->assertSee('Orders by date')
            ->assertSee('Last 7 days')
            ->assertSeeHtml('aria-label="Of those orders, how many later delivered"')
            ->call('showOrdersDateRange', 'previous')
            ->assertSet('ordersDateRange', 'previous')
            ->assertSee('Back to last 7 days')
            ->call('showOrdersDateRange', 'last7')
            ->assertSet('ordersDateRange', 'last7');

        Carbon::setTestNow();
    }
}
