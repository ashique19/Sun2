<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCostHead;
use App\Services\Admin\ProductUnitCostService;
use App\Services\Orders\OrderTotalCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductUnitCostTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'sku' => 'SKU'.random_int(1000, 9999),
            'price' => 500,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    #[Test]
    public function recalculate_sets_main_and_total_from_bom_and_heads(): void
    {
        $product = $this->product(['purchase_price' => 0, 'unit_cost' => 0]);
        $fabric = Material::query()->create([
            'name' => 'Fabric',
            'unit' => 'm',
            'unit_cost' => 50,
            'stock_quantity' => 100,
        ]);
        $pack = Material::query()->create([
            'name' => 'Bag',
            'unit' => 'pcs',
            'unit_cost' => 10,
            'stock_quantity' => 100,
        ]);

        $product->materials()->attach($fabric->id, ['quantity' => 2, 'is_primary' => true]);
        $product->materials()->attach($pack->id, ['quantity' => 1, 'is_primary' => false]);
        ProductCostHead::query()->create([
            'product_id' => $product->id,
            'name' => 'Labour',
            'amount' => 30,
            'sort_order' => 0,
        ]);

        $product = app(ProductUnitCostService::class)->recalculate($product->fresh());

        $this->assertSame(100.0, (float) $product->purchase_price); // 2 × 50 primary
        $this->assertSame(140.0, (float) $product->unit_cost); // 100 + 10 + 30
    }

    #[Test]
    public function receiving_material_stock_updates_moving_average_and_products(): void
    {
        $product = $this->product(['purchase_price' => 50, 'unit_cost' => 50]);
        $material = Material::query()->create([
            'name' => 'Beads',
            'unit' => 'pcs',
            'unit_cost' => 10,
            'stock_quantity' => 10,
        ]);
        $product->materials()->attach($material->id, ['quantity' => 5, 'is_primary' => true]);
        app(ProductUnitCostService::class)->recalculate($product->fresh());

        $result = app(ProductUnitCostService::class)->receiveStock($material->fresh(), 10, 300);
        // (10*10 + 300) / 20 = 20
        $this->assertSame(20.0, (float) $result['material']->unit_cost);
        $this->assertSame(20.0, (float) $result['material']->stock_quantity);
        $this->assertSame(1, $result['products_updated']);
        $this->assertSame(100.0, (float) $product->fresh()->unit_cost); // 5 × 20
    }

    #[Test]
    public function cogs_prefers_unit_cost_over_purchase_price(): void
    {
        $cogs = app(OrderTotalCalculator::class)->cogsFromItems([
            ['purchase_price' => 100, 'unit_cost' => 140, 'quantity' => 2, 'returned_quantity' => 0],
            ['purchase_price' => 50, 'quantity' => 1], // legacy fallback
        ]);

        $this->assertSame(330.0, $cogs); // 280 + 50
    }

    #[Test]
    public function order_product_effective_unit_cost_falls_back_to_purchase_price(): void
    {
        $product = $this->product();
        $order = Order::query()->create([
            'order_number' => 'UT-'.uniqid(),
            'status' => 'new',
            'name' => 'Buyer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'total' => 500,
        ]);

        $line = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 120,
            'unit_cost' => null,
            'line_total' => 500,
        ]);

        $this->assertSame(120.0, $line->effectiveUnitCost());
    }

    #[Test]
    public function apply_purchase_and_other_then_sync_updates_order_snapshots(): void
    {
        $product = $this->product(['purchase_price' => 0, 'unit_cost' => 0]);
        $order = Order::query()->create([
            'order_number' => 'UT-SYNC-'.uniqid(),
            'status' => 'delivered',
            'name' => 'Buyer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'total' => 500,
            'collected_amount' => 500,
        ]);
        $line = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'price' => 250,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $service = app(ProductUnitCostService::class);
        $product = $service->applyPurchaseAndOther($product, 150, 25);
        $synced = $service->syncSnapshotsToOrderProducts($product);

        $this->assertSame(1, $synced);
        $this->assertSame(150.0, (float) $product->purchase_price);
        $this->assertSame(175.0, (float) $product->unit_cost);

        $line->refresh();
        $this->assertSame(150.0, (float) $line->purchase_price);
        $this->assertSame(175.0, (float) $line->unit_cost);
        $this->assertSame(350.0, $order->fresh(['items'])->cogs());

        // Second sync with identical values still reports the matching line count.
        $this->assertSame(1, $service->syncSnapshotsToOrderProducts($product));
    }
}
