<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\ProductUnitCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductUnitCostConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Ring',
            'slug' => 'ring-'.uniqid(),
            'price' => 500,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    #[Test]
    public function sequential_material_receives_keep_moving_average_correct(): void
    {
        $product = $this->product(['purchase_price' => 50, 'unit_cost' => 50]);
        $material = Material::query()->create([
            'name' => 'Beads',
            'unit' => 'pcs',
            'unit_cost' => 10,
            'stock_quantity' => 10,
        ]);
        $product->materials()->attach($material->id, ['quantity' => 5, 'is_primary' => true]);
        $service = app(ProductUnitCostService::class);
        $service->recalculate($product->fresh());

        $service->receiveStock($material->fresh(), 10, 300); // avg 20, qty 20
        $second = $service->receiveStock($material->fresh(), 20, 200); // (20*20 + 200)/40 = 15

        $this->assertSame(15.0, (float) $second['material']->unit_cost);
        $this->assertSame(40.0, (float) $second['material']->stock_quantity);
        $this->assertSame(75.0, (float) $product->fresh()->unit_cost); // 5 × 15
    }

    #[Test]
    public function sync_snapshots_updates_many_order_lines_in_chunks(): void
    {
        $product = $this->product(['purchase_price' => 0, 'unit_cost' => 0]);
        $service = app(ProductUnitCostService::class);
        $service->applyPurchaseAndOther($product, 120, 30);

        for ($i = 0; $i < 3; $i++) {
            $order = Order::query()->create([
                'order_number' => 'CHUNK-'.$i,
                'status' => 'delivered',
                'name' => 'Buyer',
                'phone' => '0170000000'.$i,
                'address' => 'Dhaka',
                'subtotal' => 500,
                'delivery_charge' => 0,
                'total' => 500,
                'collected_amount' => 500,
            ]);

            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'price' => 500,
                'purchase_price' => 0,
                'unit_cost' => 0,
                'line_total' => 500,
            ]);
        }

        $synced = $service->syncSnapshotsToOrderProducts($product->fresh());

        $this->assertSame(3, $synced);
        $this->assertSame(3, OrderProduct::query()
            ->where('product_id', $product->id)
            ->where('unit_cost', 150)
            ->count());
    }
}
