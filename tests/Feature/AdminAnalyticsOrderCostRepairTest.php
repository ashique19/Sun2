<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\OrderCostSnapshotRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsOrderCostRepairTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function productWithCost(): Product
    {
        return Product::query()->create([
            'name' => 'Repair Ring',
            'slug' => 'repair-ring',
            'price' => 500,
            'purchase_price' => 120,
            'unit_cost' => 150,
            'is_published' => true,
        ]);
    }

    #[Test]
    public function repair_costs_modal_is_removed_from_orders_with_costs_page(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertDontSee('Repair costs…')
            ->assertSee('Audit columns…');
    }

    #[Test]
    public function repair_service_backfills_open_orders_and_clears_returned_cogs(): void
    {
        $product = $this->productWithCost();

        $open = Order::query()->create([
            'order_number' => 'REPAIR-OPEN',
            'name' => 'Open',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 500,
            'total' => 500,
            'collected_amount' => 0,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $open->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'price' => 250,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $returned = Order::query()->create([
            'order_number' => 'REPAIR-RET',
            'name' => 'Returned',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 500,
            'total' => 500,
            'collected_amount' => 0,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $returned->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 120,
            'unit_cost' => 150,
            'returned_quantity' => 0,
            'line_total' => 500,
        ]);

        $result = app(OrderCostSnapshotRepairService::class)->repairNextBatch(0, 100);

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(2, $result['fixed_orders']);
        $this->assertSame(1, $result['backfilled_lines']);
        $this->assertSame(1, $result['cleared_return_lines']);

        $open->refresh()->load('items');
        $returned->refresh()->load('items');

        $this->assertSame(150.0, (float) $open->items->first()->unit_cost);
        $this->assertSame(300.0, $open->cogs());
        $this->assertSame(0.0, (float) $returned->items->first()->unit_cost);
        $this->assertSame(0.0, $returned->cogs());
    }

    #[Test]
    public function repair_continues_across_batches(): void
    {
        $product = $this->productWithCost();

        foreach (range(1, 3) as $i) {
            $order = Order::query()->create([
                'order_number' => 'BATCH-'.$i,
                'name' => 'Batch '.$i,
                'phone' => '0171000000'.$i,
                'address' => 'Dhaka',
                'status' => 'confirmed',
                'subtotal' => 100,
                'total' => 100,
                'collected_amount' => 0,
                'placed_at' => now(),
            ]);
            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'price' => 100,
                'purchase_price' => 0,
                'unit_cost' => 0,
                'line_total' => 100,
            ]);
        }

        $service = app(OrderCostSnapshotRepairService::class);
        $this->assertSame(3, $service->eligibleOrderCount());

        $first = $service->repairNextBatch(0, 2);
        $this->assertSame(2, $first['scanned']);
        $this->assertSame(2, $first['fixed_orders']);
        $this->assertFalse($first['done']);

        $second = $service->repairNextBatch($first['next_after_id'], 2);
        $this->assertSame(1, $second['scanned']);
        $this->assertSame(1, $second['fixed_orders']);
        $this->assertTrue($second['done']);
    }

    #[Test]
    public function repair_does_not_overwrite_existing_line_costs_on_open_orders(): void
    {
        $product = $this->productWithCost();
        $order = Order::query()->create([
            'order_number' => 'KEEP-COST',
            'name' => 'Keep',
            'phone' => '01710000009',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'collected_amount' => 500,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 80,
            'unit_cost' => 90,
            'line_total' => 500,
        ]);

        $result = app(OrderCostSnapshotRepairService::class)->repairNextBatch(0, 100);

        $this->assertSame(0, $result['backfilled_lines']);
        $this->assertSame(90.0, (float) $order->fresh(['items'])->items->first()->unit_cost);
    }
}
