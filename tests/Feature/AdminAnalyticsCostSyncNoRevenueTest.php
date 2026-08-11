<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
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
    ): Order {
        $order = Order::query()->create([
            'order_number' => $orderNumber,
            'name' => 'Customer '.$orderNumber,
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => $collectedAmount > 0 ? 'delivered' : 'cancelled',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 20,
            'collected_amount' => $collectedAmount,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => $collectedAmount > 0 ? now() : null,
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

        // Only zero-revenue orders carry this product — the case that looked like "0 lines synced"
        // after save-then-sync-all rewrote the same values.
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
            ->assertSet('zeroCogs', false)
            ->assertSee('Synced')
            ->assertSee('2 order line');

        $a->refresh()->load('items');
        $b->refresh()->load('items');
        $this->assertSame(150.0, (float) $a->items->first()->unit_cost);
        $this->assertSame(150.0, (float) $b->items->first()->unit_cost);
        $this->assertSame(150.0, $a->cogs());
        $this->assertSame(150.0, $b->cogs());

        // Re-sync same values must still report both lines (not MySQL "0 changed").
        $component
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertSee('2 order line');
    }

    #[Test]
    public function sync_from_zero_revenue_order_updates_product_and_all_order_lines(): void
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
            ->assertSee('2 order line');

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
    public function sync_from_revenue_order_updates_zero_revenue_order_lines(): void
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
    public function save_on_zero_revenue_order_updates_that_order_cogs(): void
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
            ->assertSet('zeroCogs', false)
            ->assertSee('Saved');

        $order->refresh()->load('items');
        $this->assertSame(100.0, (float) $order->items->first()->unit_cost);
        $this->assertSame(100.0, $order->cogs());
    }
}
