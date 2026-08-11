<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Orders\OrderEmptyProductDefaults;
use App\Services\Orders\OrderPackagingCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderPackagingCostTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_quantity_schedule_uses_2025_rates(): void
    {
        $service = new OrderPackagingCost;

        $this->assertSame(0.0, $service->defaultForQuantity(0));
        $this->assertSame(21.0, $service->defaultForQuantity(1));
        $this->assertSame(32.0, $service->defaultForQuantity(2));
        $this->assertSame(43.0, $service->defaultForQuantity(3));
        $this->assertSame(65.0, $service->defaultForQuantity(5));
    }

    #[Test]
    public function estimate_uses_year_tiers_and_saree_handbag_piece_rate(): void
    {
        $service = new OrderPackagingCost;

        $sareeCat = Category::query()->create([
            'name' => 'Saree',
            'slug' => 'saree',
            'is_active' => true,
        ]);
        $saree = Product::query()->create([
            'category_id' => $sareeCat->id,
            'name' => 'Silk Saree',
            'slug' => 'silk-saree',
            'price' => 2000,
            'purchase_price' => 500,
            'is_published' => true,
        ]);
        $kurti = Product::query()->create([
            'name' => 'Kurti',
            'slug' => 'kurti-1',
            'price' => 800,
            'purchase_price' => 200,
            'is_published' => true,
        ]);

        $order2024 = Order::query()->create([
            'order_number' => 'PKG-2024',
            'name' => 'A',
            'phone' => '017',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
            'placed_at' => '2024-06-15 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order2024->id,
            'product_id' => $kurti->id,
            'name' => 'Kurti',
            'quantity' => 2,
            'price' => 400,
            'purchase_price' => 100,
            'line_total' => 800,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order2024->id,
            'product_id' => $saree->id,
            'name' => 'Silk Saree',
            'quantity' => 1,
            'price' => 2000,
            'purchase_price' => 500,
            'line_total' => 2000,
        ]);

        // 2 standard @ 35+17=52 + 1 saree @ 48 = 100
        $this->assertSame(100.0, $service->estimateFor($order2024->fresh(['items.product.category'])));

        $order2025 = Order::query()->create([
            'order_number' => 'PKG-2025',
            'name' => 'B',
            'phone' => '017',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
            'placed_at' => '2025-02-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order2025->id,
            'product_id' => $saree->id,
            'name' => 'Silk Saree',
            'quantity' => 2,
            'price' => 2000,
            'purchase_price' => 500,
            'line_total' => 4000,
        ]);

        // 2025+: no saree exception → 21+11=32
        $this->assertSame(32.0, $service->estimateFor($order2025->fresh(['items.product.category'])));
    }

    #[Test]
    public function empty_order_uses_flat_packaging_of_21(): void
    {
        $service = new OrderPackagingCost;

        $order = Order::query()->create([
            'order_number' => 'PKG-EMPTY',
            'name' => 'Empty',
            'phone' => '017',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 0,
            'total' => 0,
            'packaging_cost' => 0,
            'placed_at' => '2024-06-15 10:00:00',
        ]);

        $this->assertSame(21.0, $service->estimateFor($order->fresh(['items'])));
        $this->assertSame(OrderEmptyProductDefaults::PACKAGING, $service->estimateFor($order->fresh(['items'])));
    }
}
