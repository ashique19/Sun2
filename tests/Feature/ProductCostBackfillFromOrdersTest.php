<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\OrderCalculationAuditService;
use App\Services\Admin\ProductUnitCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCostBackfillFromOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function fills_product_unit_cost_from_newest_order_line_snapshot(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Ring',
            'slug' => 'ring-backfill',
            'sku' => 'RB-1',
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $older = Order::query()->create([
            'order_number' => 'OLD-1',
            'name' => 'A',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'collected_amount' => 500,
            'placed_at' => now()->subDays(10),
        ]);
        OrderProduct::query()->create([
            'order_id' => $older->id,
            'product_id' => $product->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'line_total' => 500,
        ]);

        $newer = Order::query()->create([
            'order_number' => 'NEW-1',
            'name' => 'B',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'collected_amount' => 500,
            'placed_at' => now()->subDay(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $newer->id,
            'product_id' => $product->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 150,
            'unit_cost' => 180,
            'line_total' => 500,
        ]);

        $updated = app(ProductUnitCostService::class)->backfillMissingFromOrderSnapshots($product);

        $this->assertTrue($updated);
        $product->refresh();
        $this->assertSame(150.0, (float) $product->purchase_price);
        $this->assertSame(180.0, (float) $product->unit_cost);
        $this->assertSame(30.0, (float) $product->costHeads()->sum('amount'));
    }

    #[Test]
    public function audit_auto_fixes_by_backfilling_product_from_order_cogs(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Necklace',
            'slug' => 'necklace-backfill',
            'sku' => 'NB-1',
            'price' => 800,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'AUD-PROD-COST',
            'name' => 'Customer',
            'phone' => '01710000003',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 800,
            'total' => 800,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'collected_amount' => 800,
            'paid_amount' => 800,
            'payment_status' => 'paid',
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 800,
            'purchase_price' => 220,
            'unit_cost' => 250,
            'line_total' => 800,
        ]);

        $zeroLineOrder = Order::query()->create([
            'order_number' => 'AUD-ZERO-LINE',
            'name' => 'Needs Backfill',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 800,
            'total' => 800,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-02 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $zeroLineOrder->id,
            'product_id' => $product->id,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 800,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 800,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCalculationAuditModal')
            ->call('startCalculationAudit')
            ->assertSet('auditDone', true)
            ->assertSet('auditManualNeeded', 0);

        $product->refresh();
        $this->assertSame(220.0, (float) $product->purchase_price);
        $this->assertSame(250.0, (float) $product->unit_cost);

        $zeroLineOrder->refresh()->load('items');
        $this->assertSame(250.0, (float) $zeroLineOrder->items->first()->unit_cost);
        $this->assertSame(250.0, $zeroLineOrder->cogs());
    }

    #[Test]
    public function does_not_overwrite_product_that_already_has_unit_cost(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Kept',
            'slug' => 'kept-cost',
            'sku' => 'KC-1',
            'price' => 500,
            'purchase_price' => 90,
            'unit_cost' => 110,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'KEEP-1',
            'name' => 'A',
            'phone' => '01710000005',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Kept',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        $this->assertFalse(app(ProductUnitCostService::class)->backfillMissingFromOrderSnapshots($product));
        $product->refresh();
        $this->assertSame(90.0, (float) $product->purchase_price);
        $this->assertSame(110.0, (float) $product->unit_cost);
    }

    #[Test]
    public function audit_service_marks_product_backfill_as_auto_fixed(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Earring',
            'slug' => 'earring-backfill',
            'sku' => 'EB-1',
            'price' => 400,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'SVC-1',
            'name' => 'A',
            'phone' => '01710000006',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 400,
            'total' => 400,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-04-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Earring',
            'quantity' => 1,
            'price' => 400,
            'purchase_price' => 75,
            'unit_cost' => 75,
            'line_total' => 400,
        ]);

        $result = app(OrderCalculationAuditService::class)->auditOrder($order->fresh());

        $this->assertTrue($result['auto_fixed']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(75.0, (float) $product->fresh()->unit_cost);
    }

    #[Test]
    public function fills_zero_line_from_same_short_name_on_another_order(): void
    {
        $courier = $this->steadfast();

        $source = Order::query()->create([
            'order_number' => 'NAME-SRC',
            'name' => 'A',
            'phone' => '01710000010',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 600,
            'total' => 600,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'collected_amount' => 600,
            'placed_at' => now()->subDay(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $source->id,
            'product_id' => null,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 600,
            'purchase_price' => 140,
            'unit_cost' => 160,
            'line_total' => 600,
        ]);

        $target = Order::query()->create([
            'order_number' => 'NAME-TGT',
            'name' => 'B',
            'phone' => '01710000011',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 600,
            'total' => 600,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $target->id,
            'product_id' => null,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 600,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 600,
        ]);

        $result = app(ProductUnitCostService::class)->backfillMissingCostsForOrder($target->fresh(['items']));

        $this->assertSame(0, $result['products_updated']);
        $this->assertSame(1, $result['lines_updated']);
        $this->assertSame(160.0, (float) $target->fresh('items')->items->first()->unit_cost);
    }

    #[Test]
    public function fills_linked_zero_line_from_short_name_even_when_catalog_title_differs(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Ethnic Oxidized Silver Sunflower Ring | Traditional Floral Jewellery',
            'slug' => 'sunflower-ring',
            'sku' => 'SR-1',
            'price' => 450,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $peer = Order::query()->create([
            'order_number' => 'PEER-RING',
            'name' => 'A',
            'phone' => '01710000012',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 450,
            'total' => 450,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'collected_amount' => 450,
            'placed_at' => now()->subDay(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $peer->id,
            'product_id' => null,
            'name' => 'Finger Ring',
            'quantity' => 1,
            'price' => 450,
            'purchase_price' => 80,
            'unit_cost' => 95,
            'line_total' => 450,
        ]);

        $order = Order::query()->create([
            'order_number' => 'ZERO-RING',
            'name' => 'B',
            'phone' => '01710000013',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 450,
            'total' => 450,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Finger Ring',
            'quantity' => 1,
            'price' => 450,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 450,
        ]);

        $result = app(OrderCalculationAuditService::class)->auditOrder($order->fresh());

        $this->assertTrue($result['auto_fixed']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(95.0, (float) $order->fresh('items')->items->first()->unit_cost);
        $this->assertSame(95.0, (float) $product->fresh()->unit_cost);
    }

    #[Test]
    public function treats_zero_unit_cost_as_unset_and_uses_purchase_price(): void
    {
        $product = Product::query()->create([
            'name' => 'Purchase Only',
            'slug' => 'purchase-only',
            'sku' => 'PO-1',
            'price' => 300,
            'purchase_price' => 120,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $this->assertSame(120.0, $product->effectiveUnitCost());
        $this->assertSame(120.0, app(ProductUnitCostService::class)->effectiveUnitCost($product));

        $line = new OrderProduct([
            'purchase_price' => 110,
            'unit_cost' => 0,
        ]);

        $this->assertSame(110.0, $line->effectiveUnitCost());
    }
}
