<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\SalesReportService;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesReportKeptQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::query()->create([
            'name' => 'Kurtis',
            'slug' => 'kurtis-'.uniqid(),
            'is_active' => true,
            'display_order' => 0,
        ]);

        return Product::query()->create([
            'name' => 'Kept Qty Kurti',
            'slug' => 'kept-qty-kurti-'.uniqid(),
            'sku' => 'KQ-'.uniqid(),
            'price' => 1100,
            'purchase_price' => 400,
            'stock_quantity' => 20,
            'is_published' => true,
            'display_order' => 0,
            'category_id' => $category->id,
        ]);
    }

    #[Test]
    public function product_delivered_volume_uses_kept_qty_not_shipped_qty(): void
    {
        $product = $this->product();
        $placedAt = now('Asia/Dhaka')->startOfMonth()->addDays(2)->utc();

        $order = Order::query()->create([
            'order_number' => 'KEPT-1',
            'name' => 'Customer',
            'phone' => '01710000061',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 2200,
            'delivery_charge' => 80,
            'total' => 1180,
            'collected_amount' => 2280,
            'placed_at' => $placedAt,
            'created_at' => $placedAt,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'returned_quantity' => 1,
            'price' => 1100,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 2200,
        ]);

        $summary = app(SalesReportService::class)->productSummary($product);

        $this->assertSame(2, $summary['sales_volume']);
        $this->assertSame(1, $summary['delivered_volume']);
        $this->assertEquals(1100.0, $summary['delivered_value']);
        $this->assertSame(1, $summary['returned_volume']);
    }

    #[Test]
    public function dashboard_category_delivery_value_uses_kept_merchandise(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'Asia/Dhaka'));

        $product = $this->product();
        $placedAt = Carbon::parse('2026-08-05 10:00:00', 'Asia/Dhaka')->utc();

        $order = Order::query()->create([
            'order_number' => 'KEPT-DASH',
            'name' => 'Customer',
            'phone' => '01710000062',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 2200,
            'total' => 1180,
            'collected_amount' => 2280,
            'placed_at' => $placedAt,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'returned_quantity' => 1,
            'price' => 1100,
            'purchase_price' => 400,
            'line_total' => 2200,
        ]);

        $report = AdminDashboardMetrics::orderAndDeliveryByCategory(fresh: true);
        $row = collect($report['rows'])->firstWhere('name', 'Kurtis');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['this_month']['delivery_qty']);
        $this->assertEquals(1100.0, $row['this_month']['delivery_value']);
        $this->assertEquals(2200.0, $row['this_month']['order_value']);

        Carbon::setTestNow();
    }
}
