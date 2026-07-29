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

    #[Test]
    public function daily_totals_use_collected_amount_for_delivery_value(): void
    {
        $today = now()->startOfDay()->addHours(10);
        $yesterday = now()->subDay()->startOfDay()->addHours(11);

        $this->order([
            'placed_at' => $today,
            'delivery_charge' => 90,
            'total' => 1590,
            'collected_amount' => 1200,
        ]);
        $this->order([
            'placed_at' => $today,
            'delivery_charge' => 70,
            'total' => 870,
            'collected_amount' => 300,
        ]);
        $this->order([
            'placed_at' => $yesterday,
            'delivery_charge' => 60,
            'total' => 560,
            'collected_amount' => 450,
        ]);
        $this->order([
            'placed_at' => $today,
            'status' => Order::STATUS_DRAFT,
            'collected_amount' => 999,
        ]);

        $rows = AdminDashboardMetrics::dailyTotals(2, fresh: true);

        $this->assertCount(2, $rows);
        $this->assertSame($today->toDateString(), $rows[0]['date']);
        $this->assertSame(1500.0, $rows[0]['delivery_value']);
        $this->assertSame($yesterday->toDateString(), $rows[1]['date']);
        $this->assertSame(450.0, $rows[1]['delivery_value']);
    }
}
