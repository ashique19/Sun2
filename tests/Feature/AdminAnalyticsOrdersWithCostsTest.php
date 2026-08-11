<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
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

class AdminAnalyticsOrdersWithCostsTest extends TestCase
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

    private function orderWithCosts(Courier $courier, ?Product $product = null, float $unitCost = 200): Order
    {
        $order = Order::query()->create([
            'order_number' => 'COST-1001',
            'name' => 'Cost Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 20,
            'collected_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'name' => $product?->name ?? 'Ring',
            'quantity' => 2,
            'price' => 500,
            'purchase_price' => $unitCost,
            'unit_cost' => $unitCost,
            'line_total' => 1000,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function analytics_hub_links_to_orders_with_costs(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalytics::class)
            ->assertSee('All orders with costs')
            ->assertSee(route('admin.analytics.orders-with-costs'), false);
    }

    #[Test]
    public function page_lists_order_revenue_costs_and_profit(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithCosts($this->steadfast());

        // COGS 400 + pack 20 + courier 60 + COD (1080-80)*1% = 10 → direct 490
        // P/L = 1080 - 490 = 590 · P/L % = 590/1080 ≈ 54.6% (shown under P/L)
        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertSee('All orders with costs')
            ->assertSeeHtml(route('admin.orders.show', $order))
            ->assertSee('COST-1001')
            ->assertSee('৳ 1,080')
            ->assertSee('৳ 400')
            ->assertSee('৳ 20')
            ->assertSee('৳ 60')
            ->assertSee('৳ 10')
            ->assertSee('৳ 490')
            ->assertSee('৳ 590')
            ->assertDontSeeHtml('>P/L %</')
            ->assertSee('37.0%') // COGS
            ->assertSee('1.9%') // packaging
            ->assertSee('5.6%') // courier
            ->assertSee('0.9%') // COD
            ->assertSee('45.4%') // direct
            ->assertSee('54.6%'); // P/L
    }

    #[Test]
    public function packaging_and_courier_are_editable_inline(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithCosts($this->steadfast());

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('startInlineEdit', $order->id, 'packaging_cost', '20')
            ->set('editingValue', '35')
            ->call('saveInlineEdit')
            ->call('startInlineEdit', $order->id, 'courier_charge', '60')
            ->set('editingValue', '75')
            ->call('saveInlineEdit');

        $order->refresh();

        $this->assertSame(35.0, (float) $order->packaging_cost);
        $this->assertSame(75.0, (float) $order->courier_charge);
    }

    #[Test]
    public function cogs_inline_edit_scales_line_unit_costs(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithCosts($this->steadfast());

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('startInlineEdit', $order->id, 'cogs', '400')
            ->set('editingValue', '500')
            ->call('saveInlineEdit');

        $order->refresh()->load('items');

        $this->assertSame(500.0, $order->cogs());
        $this->assertSame(250.0, (float) $order->items->first()->unit_cost);
    }

    #[Test]
    public function zero_cogs_double_click_opens_product_cost_modal(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Zero Cost Ring',
            'slug' => 'zero-cost-ring',
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $order = $this->orderWithCosts($this->steadfast(), $product, 0);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('startInlineEdit', $order->id, 'cogs', '0')
            ->assertSet('cogsModalOpen', true)
            ->assertSet('cogsModalOrderId', $order->id)
            ->assertSee('Fix product costs')
            ->assertSee('Zero Cost Ring')
            ->assertSee('Save product + this order')
            ->assertSee('Sync to all orders with this product');
    }

    #[Test]
    public function modal_saves_product_costs_and_syncs_all_order_lines(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Sync Ring',
            'slug' => 'sync-ring',
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $orderA = $this->orderWithCosts($courier, $product, 0);

        $orderB = Order::query()->create([
            'order_number' => 'COST-2002',
            'name' => 'Other Customer',
            'phone' => '01710000088',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 10,
            'collected_amount' => 580,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $orderB->id,
            'product_id' => $product->id,
            'name' => 'Sync Ring',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCogsModal', $orderA->id)
            ->set('cogsModalRows.0.purchase_price', '180')
            ->set('cogsModalRows.0.other_cost', '20')
            ->call('syncCogsModalRowToAllOrders', 0)
            ->assertSet('cogsModalOpen', true)
            ->assertSee('Synced');

        $product->refresh();
        $this->assertSame(180.0, (float) $product->purchase_price);
        $this->assertSame(200.0, (float) $product->unit_cost);

        $orderA->refresh()->load('items');
        $orderB->refresh()->load('items');

        $this->assertSame(200.0, (float) $orderA->items->first()->unit_cost);
        $this->assertSame(180.0, (float) $orderA->items->first()->purchase_price);
        $this->assertSame(400.0, $orderA->cogs()); // 2 × 200

        $this->assertSame(200.0, (float) $orderB->items->first()->unit_cost);
        $this->assertSame(200.0, $orderB->cogs());
    }

    #[Test]
    public function search_filters_orders_by_number(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $this->orderWithCosts($courier);

        Order::query()->create([
            'order_number' => 'OTHER-9',
            'name' => 'Other',
            'phone' => '01710000011',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 100,
            'total' => 100,
            'placed_at' => now(),
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->set('search', 'COST-1001')
            ->assertSee('COST-1001')
            ->assertDontSee('OTHER-9');
    }

    #[Test]
    public function zero_checkboxes_filter_each_numeric_column(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $withCosts = $this->orderWithCosts($courier);
        $withCosts->update(['order_number' => 'HAS-COSTS']);

        $zeroCogsProduct = Product::query()->create([
            'name' => 'Zero',
            'slug' => 'zero-cogs-filter',
            'price' => 100,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $zeroCogs = Order::query()->create([
            'order_number' => 'ZERO-COGS',
            'name' => 'Zero Cogs Customer',
            'phone' => '01710000077',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 20,
            'collected_amount' => 580,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $zeroCogs->id,
            'product_id' => $zeroCogsProduct->id,
            'name' => 'Zero',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $zeroPack = Order::query()->create([
            'order_number' => 'ZERO-PACK',
            'name' => 'Zero Pack',
            'phone' => '01710000066',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 0,
            'collected_amount' => 580,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $zeroPack->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 100,
            'unit_cost' => 100,
            'line_total' => 500,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertSeeHtml('aria-label="COGS is ৳0"')
            ->assertSeeHtml('aria-label="P/L is ৳0"')
            ->set('zeroCogs', true)
            ->assertSee('ZERO-COGS')
            ->assertDontSee('HAS-COSTS')
            ->set('zeroCogs', false)
            ->set('zeroPackaging', true)
            ->assertSee('ZERO-PACK')
            ->assertDontSee('HAS-COSTS');
    }
}
