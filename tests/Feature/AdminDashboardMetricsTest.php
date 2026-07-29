<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    #[Test]
    public function daily_totals_use_delivered_orders_by_delivery_date_for_qty_and_collected_value(): void
    {
        $today = now()->startOfDay()->addHours(10);
        $yesterday = now()->subDay()->startOfDay()->addHours(11);
        $twoDaysAgo = now()->subDays(2)->startOfDay()->addHours(9);

        // Placed today, delivered today — counts for both order and delivery metrics today.
        $deliveredToday = $this->order([
            'placed_at' => $today,
            'status' => 'delivered',
            'actual_delivery_date' => $today,
            'collected_amount' => 1200,
            'total' => 1590,
        ]);
        $this->addItem($deliveredToday, 3, 1); // net delivered qty = 2

        // Placed yesterday, delivered today — order metrics yesterday; delivery metrics today.
        $deliveredTodayPlacedEarlier = $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $today,
            'collected_amount' => 300,
            'total' => 870,
        ]);
        $this->addItem($deliveredTodayPlacedEarlier, 4);

        // Placed & delivered yesterday.
        $deliveredYesterday = $this->order([
            'placed_at' => $yesterday,
            'status' => 'delivered',
            'actual_delivery_date' => $yesterday,
            'collected_amount' => 450,
            'total' => 560,
        ]);
        $this->addItem($deliveredYesterday, 1);

        // Placed today but not delivered — order metrics only.
        $pending = $this->order([
            'placed_at' => $today,
            'status' => 'new',
            'collected_amount' => 999,
            'total' => 500,
        ]);
        $this->addItem($pending, 10);

        // Draft — ignored entirely.
        $draft = $this->order([
            'placed_at' => $today,
            'status' => Order::STATUS_DRAFT,
            'collected_amount' => 100,
        ]);
        $this->addItem($draft, 5);

        // Delivered without delivery date — excluded from delivery metrics.
        $missingDate = $this->order([
            'placed_at' => $twoDaysAgo,
            'status' => 'delivered',
            'actual_delivery_date' => null,
            'collected_amount' => 200,
        ]);
        $this->addItem($missingDate, 2);

        $rows = AdminDashboardMetrics::dailyTotals(3, fresh: true);

        $this->assertCount(3, $rows);
        $this->assertSame($today->toDateString(), $rows[0]['date']);
        $this->assertSame(2, $rows[0]['order_qty']);
        $this->assertSame(2090.0, $rows[0]['order_value']);
        $this->assertSame(6, $rows[0]['delivery_qty']); // 2 + 4
        $this->assertSame(1500.0, $rows[0]['delivery_value']); // 1200 + 300

        $this->assertSame($yesterday->toDateString(), $rows[1]['date']);
        $this->assertSame(2, $rows[1]['order_qty']);
        $this->assertSame(1430.0, $rows[1]['order_value']);
        $this->assertSame(1, $rows[1]['delivery_qty']);
        $this->assertSame(450.0, $rows[1]['delivery_value']);

        $this->assertSame($twoDaysAgo->toDateString(), $rows[2]['date']);
        $this->assertSame(1, $rows[2]['order_qty']);
        $this->assertSame(0, $rows[2]['delivery_qty']);
        $this->assertSame(0.0, $rows[2]['delivery_value']);
    }
}
