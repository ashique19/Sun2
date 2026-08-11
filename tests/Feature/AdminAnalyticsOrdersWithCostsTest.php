<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
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

    private function orderWithCosts(Courier $courier): Order
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
            'name' => 'Ring',
            'quantity' => 2,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
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
        // P/L = 1080 - 490 = 590
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
            ->assertSee('৳ 590');
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
}
