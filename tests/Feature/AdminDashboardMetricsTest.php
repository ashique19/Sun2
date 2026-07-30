<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

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

    private function addItem(Order $order, int $quantity, int $returnedQuantity = 0): void
    {
        $order->items()->create([
            'name' => 'Product',
            'quantity' => $quantity,
            'returned_quantity' => $returnedQuantity,
            'price' => 100,
            'purchase_price' => 50,
            'line_total' => 100 * $quantity,
        ]);
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
    public function daily_totals_group_current_and_previous_month_with_m_d_labels(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00'));

        $today = now()->startOfDay()->addHours(10);
        $yesterday = now()->subDay()->startOfDay()->addHours(11);
        $previousMonthDay = now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->startOfDay()->addHours(9);

        $deliveredToday = $this->order([
            'placed_at' => $today,
            'status' => 'delivered',
            'actual_delivery_date' => $today,
            'collected_amount' => 1200,
            'total' => 1590,
        ]);
        $this->addItem($deliveredToday, 3, 1);

        $deliveredTodayPlacedEarlier = $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $today,
            'collected_amount' => 300,
            'total' => 870,
        ]);
        $this->addItem($deliveredTodayPlacedEarlier, 4);

        $deliveredYesterday = $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $yesterday,
            'collected_amount' => 450,
            'total' => 560,
        ]);
        $this->addItem($deliveredYesterday, 1);

        $pending = $this->order([
            'placed_at' => $today,
            'status' => 'new',
            'collected_amount' => 999,
            'total' => 500,
        ]);
        $this->addItem($pending, 10);

        $draft = $this->order([
            'placed_at' => $today,
            'status' => Order::STATUS_DRAFT,
            'collected_amount' => 100,
        ]);
        $this->addItem($draft, 5);

        $previousMonthOrder = $this->order([
            'placed_at' => $previousMonthDay,
            'status' => 'delivered',
            'actual_delivery_date' => $previousMonthDay,
            'collected_amount' => 700,
            'total' => 800,
        ]);
        $this->addItem($previousMonthOrder, 2);

        $months = AdminDashboardMetrics::dailyTotals(fresh: true);

        $this->assertCount(2, $months);
        $this->assertSame('Current month', $months[0]['label']);
        $this->assertTrue($months[0]['is_current']);
        $this->assertSame('2026-07', $months[0]['key']);
        $this->assertSame('Previous month', $months[1]['label']);
        $this->assertFalse($months[1]['is_current']);
        $this->assertSame('2026-06', $months[1]['key']);

        $this->assertSame('Jul-29', $months[0]['days'][0]['label']);
        $this->assertSame($today->toDateString(), $months[0]['days'][0]['date']);
        $this->assertSame('Jun-01', $months[1]['days'][array_key_last($months[1]['days'])]['label']);

        $todayRow = $this->dayRow($months[0]['days'], $today->toDateString());
        $yesterdayRow = $this->dayRow($months[0]['days'], $yesterday->toDateString());
        $previousRow = $this->dayRow($months[1]['days'], $previousMonthDay->toDateString());

        // DQ/CV follow placement day cohort (of orders placed that day, how many delivered / collected).
        $this->assertNotNull($todayRow);
        $this->assertSame(2, $todayRow['order_qty']);
        $this->assertSame(2090.0, $todayRow['order_value']);
        $this->assertSame(2, $todayRow['delivery_qty']); // deliveredToday net items (3-1); pending excluded
        $this->assertSame(1200.0, $todayRow['delivery_value']);

        $this->assertNotNull($yesterdayRow);
        $this->assertSame(2, $yesterdayRow['order_qty']);
        $this->assertSame(1430.0, $yesterdayRow['order_value']);
        $this->assertSame(5, $yesterdayRow['delivery_qty']); // 4 + 1 from both delivered orders placed yesterday
        $this->assertSame(750.0, $yesterdayRow['delivery_value']); // 300 + 450

        $this->assertNotNull($previousRow);
        $this->assertSame('Jun-05', $previousRow['label']);
        $this->assertSame(1, $previousRow['order_qty']);
        $this->assertSame(800.0, $previousRow['order_value']);
        $this->assertSame(2, $previousRow['delivery_qty']);
        $this->assertSame(700.0, $previousRow['delivery_value']);

        $this->assertSame(4, $months[0]['totals']['order_qty']);
        $this->assertSame(1, $months[1]['totals']['order_qty']);
        $this->assertCount(29, $months[0]['days']);
        $this->assertCount(30, $months[1]['days']);

        Carbon::setTestNow();
    }
}
