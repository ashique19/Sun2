<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\ProductUnitCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsCostSyncNoRevenueTest extends TestCase
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

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'No Revenue Ring',
            'slug' => 'no-revenue-ring',
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);
    }

    private function orderWithProduct(
        Courier $courier,
        Product $product,
        string $orderNumber,
        float $collectedAmount,
        float $lineUnitCost = 0,
        string $status = 'auto',
    ): Order {
        if ($status === 'auto') {
            // Zero collected is often "not settled yet", not a return.
            $status = $collectedAmount > 0 ? 'delivered' : 'confirmed';
        }

        $order = Order::query()->create([
            'order_number' => $orderNumber,
            'name' => 'Customer '.$orderNumber,
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => $status,
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 20,
            'collected_amount' => $collectedAmount,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => $status === 'delivered' ? now() : null,
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => $lineUnitCost,
            'unit_cost' => $lineUnitCost,
            'line_total' => 500,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function sync_count_includes_lines_even_when_values_already_match(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $product = $this->product();

        // Active orders with ৳0 collected — still should receive cost snapshots.
        $a = $this->orderWithProduct($courier, $product, 'ZERO-ONLY-A', 0);
        $b = $this->orderWithProduct($courier, $product, 'ZERO-ONLY-B', 0);

        $component = Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->set('zeroRevenue', true)
            ->set('zeroCogs', true)
            ->assertSee('ZERO-ONLY-A')
            ->call('openCogsModal', $a->id)
            ->set('cogsModalRows.0.purchase_price', '120')
            ->set('cogsModalRows.0.other_cost', '30')
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertHasNoErrors()
            ->assertSet('zeroCogs', true)
            ->assertSee('Synced')
            ->assertSee('2 open order line');

        $a->refresh()->load('items');
        $b->refresh()->load('items');
        $this->assertSame(150.0, (float) $a->items->first()->unit_cost);
        $this->assertSame(150.0, (float) $b->items->first()->unit_cost);
        $this->assertSame(150.0, $a->cogs());
        $this->assertSame(150.0, $b->cogs());

        // Re-sync same values must still report both lines (not MySQL "0 changed").
        $component
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertSee('2 open order line');
    }

    #[Test]
    public function sync_skips_cancelled_and_returned_orders(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $product = $this->product();

        $active = $this->orderWithProduct($courier, $product, 'ACTIVE-1', 580, status: 'delivered');
        $cancelled = $this->orderWithProduct($courier, $product, 'CANCEL-1', 0, status: 'cancelled');
        $returned = $this->orderWithProduct($courier, $product, 'RETURN-1', 0, status: 'returned');

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCogsModal', $active->id)
            ->set('cogsModalRows.0.purchase_price', '180')
            ->set('cogsModalRows.0.other_cost', '20')
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertHasNoErrors()
            ->assertSee('1 open order line');

        $active->refresh()->load('items');
        $cancelled->refresh()->load('items');
        $returned->refresh()->load('items');

        $this->assertSame(200.0, (float) $active->items->first()->unit_cost);
        $this->assertSame(200.0, $active->cogs());

        // Returns/cancels keep zero line costs — inventing COGS there is wrong.
        $this->assertSame(0.0, (float) $cancelled->items->first()->unit_cost);
        $this->assertSame(0.0, (float) $returned->items->first()->unit_cost);
        $this->assertSame(0.0, $cancelled->cogs());
        $this->assertSame(0.0, $returned->cogs());
    }

    #[Test]
    public function service_sync_skips_cancelled_even_when_targeting_that_order(): void
    {
        $product = $this->product();
        $courier = $this->steadfast();
        $cancelled = $this->orderWithProduct($courier, $product, 'CANCEL-ONLY', 0, status: 'cancelled');

        $service = app(ProductUnitCostService::class);
        $product = $service->applyPurchaseAndOther($product, 100, 0);
        $synced = $service->syncSnapshotsToOrderProducts($product, $cancelled->id);

        $this->assertSame(0, $synced);
        $this->assertSame(0.0, (float) $cancelled->fresh(['items'])->items->first()->unit_cost);
        $this->assertSame(0.0, $cancelled->fresh(['items'])->cogs());
    }

    #[Test]
    public function sync_from_zero_revenue_active_order_updates_product_and_open_order_lines(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $product = $this->product();

        $zeroRevenue = $this->orderWithProduct($courier, $product, 'ZERO-REV-1', 0);
        $withRevenue = $this->orderWithProduct($courier, $product, 'HAS-REV-1', 580);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->set('zeroRevenue', true)
            ->assertSee('ZERO-REV-1')
            ->assertDontSee('HAS-REV-1')
            ->call('openCogsModal', $zeroRevenue->id)
            ->set('cogsModalRows.0.purchase_price', '150')
            ->set('cogsModalRows.0.other_cost', '25')
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertHasNoErrors()
            ->assertSet('cogsModalOpen', true)
            ->assertSee('Synced')
            ->assertSee('2 open order line');

        $product->refresh();
        $this->assertSame(150.0, (float) $product->purchase_price);
        $this->assertSame(175.0, (float) $product->unit_cost);

        $zeroRevenue->refresh()->load('items');
        $withRevenue->refresh()->load('items');

        $this->assertSame(175.0, (float) $zeroRevenue->items->first()->unit_cost);
        $this->assertSame(150.0, (float) $zeroRevenue->items->first()->purchase_price);
        $this->assertSame(175.0, $zeroRevenue->cogs());

        $this->assertSame(175.0, (float) $withRevenue->items->first()->unit_cost);
        $this->assertSame(175.0, $withRevenue->cogs());
    }

    #[Test]
    public function sync_from_revenue_order_updates_zero_revenue_active_order_lines(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $product = $this->product();

        $withRevenue = $this->orderWithProduct($courier, $product, 'HAS-REV-2', 580);
        $zeroRevenue = $this->orderWithProduct($courier, $product, 'ZERO-REV-2', 0);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCogsModal', $withRevenue->id)
            ->set('cogsModalRows.0.purchase_price', '200')
            ->set('cogsModalRows.0.other_cost', '0')
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertHasNoErrors()
            ->assertSee('Synced');

        $zeroRevenue->refresh()->load('items');
        $this->assertSame(200.0, (float) $zeroRevenue->items->first()->unit_cost);
        $this->assertSame(200.0, $zeroRevenue->cogs());
    }

    #[Test]
    public function save_on_zero_revenue_active_order_updates_that_order_cogs(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $product = $this->product();
        $order = $this->orderWithProduct($courier, $product, 'ZERO-REV-3', 0);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->set('zeroRevenue', true)
            ->set('zeroCogs', true)
            ->call('openCogsModal', $order->id)
            ->set('cogsModalRows.0.purchase_price', '90')
            ->set('cogsModalRows.0.other_cost', '10')
            ->call('saveCogsModalRow', 0)
            ->assertHasNoErrors()
            ->assertSet('zeroCogs', true)
            ->assertSee('Saved');

        $order->refresh()->load('items');
        $this->assertSame(100.0, (float) $order->items->first()->unit_cost);
        $this->assertSame(100.0, $order->cogs());
    }
}
