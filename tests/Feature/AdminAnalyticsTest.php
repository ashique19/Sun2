<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
use App\Livewire\Admin\AdminAnalyticsCategoryRevenue;
use App\Livewire\Admin\AdminAnalyticsDetail;
use App\Livewire\Admin\AdminAnalyticsOrderedDelivered;
use App\Livewire\Admin\AdminAnalyticsPnl;
use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function seedDeliveredOrder(
        Courier $courier,
        string $name,
        float $collected,
        float $courierCharge,
        float $packaging,
        int $qty,
        float $purchasePrice,
        string $deliveredAt,
        ?string $placedAt = null,
        ?int $productId = null,
    ): Order {
        $order = Order::query()->create([
            'order_number' => 'AN-'.uniqid(),
            'name' => $name,
            'phone' => '01710000000',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => $collected - 80,
            'delivery_charge' => 80,
            'courier_charge' => $courierCharge,
            'packaging_cost' => $packaging,
            'collected_amount' => $collected,
            'total' => $collected,
            'courier_id' => $courier->id,
            'actual_delivery_date' => $deliveredAt,
            'placed_at' => $placedAt ?? $deliveredAt,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'name' => 'Product',
            'quantity' => $qty,
            'price' => 500,
            'purchase_price' => $purchasePrice,
            'line_total' => 500 * $qty,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function analytics_hub_lists_report_tiles(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalytics::class)
            ->assertSee('Analytics')
            ->assertSee('Profit & loss')
            ->assertSee('Ordered vs delivered')
            ->assertSee('Revenue by category')
            ->assertSee('Investor pitch');
    }

    #[Test]
    public function pnl_page_shows_year_donut_and_drills_into_month(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $this->seedDeliveredOrder($courier, 'Ayesha', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00');
        $this->seedDeliveredOrder($courier, 'Karim', 2160, 75, 30, 2, 400, '2026-07-20 12:00:00');

        Livewire::test(AdminAnalyticsPnl::class)
            ->set('year', 2026)
            ->assertSee('Profit & loss')
            ->assertSee('Months in 2026')
            ->assertSee('Jul')
            ->assertSee('Select a month')
            ->call('selectMonth', 7)
            ->assertSet('month', 7)
            ->assertSee('July 2026')
            ->assertSee('Open →')
            ->assertSee('Revenue')
            ->assertSee('Direct cost')
            ->assertSee('Indirect cost')
            ->assertSeeHtml("wire:click=\"openMetric('revenue')\"")
            ->call('nextMonth')
            ->assertSet('month', 8)
            ->call('previousMonth')
            ->assertSet('month', 7);
    }

    #[Test]
    public function ordered_vs_delivered_page_renders_charts(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $this->seedDeliveredOrder($courier, 'Ayesha', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00');

        Livewire::test(AdminAnalyticsOrderedDelivered::class)
            ->set('year', 2026)
            ->assertSee('Ordered vs delivered')
            ->assertSee('Order count')
            ->assertSee('Order value')
            ->assertSee('Jul');
    }

    #[Test]
    public function ordered_vs_delivered_uses_placement_month_cohort(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        // Placed in June, delivered in July
        $this->seedDeliveredOrder(
            $courier,
            'Ayesha',
            1080,
            65,
            21,
            1,
            400,
            '2026-07-15 10:00:00',
            '2026-06-20 09:00:00',
        );

        // Placed and delivered in July
        $this->seedDeliveredOrder(
            $courier,
            'Karim',
            2160,
            75,
            30,
            2,
            400,
            '2026-07-22 12:00:00',
            '2026-07-01 08:00:00',
        );

        // Still open — placed in July, not delivered
        Order::query()->create([
            'order_number' => 'AN-pending-'.uniqid(),
            'name' => 'Pending',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'total' => 580,
            'courier_id' => $courier->id,
            'placed_at' => '2026-07-10 08:00:00',
        ]);

        $report = app(AnalyticsService::class)->orderedVsDeliveredByMonth(2026);
        $byMonth = collect($report['months'])->keyBy('month');

        // June cohort: one order placed, later delivered (even though delivery was in July)
        $this->assertSame(1, $byMonth[6]['ordered_count']);
        $this->assertSame(1080.0, $byMonth[6]['ordered_value']);
        $this->assertSame(1, $byMonth[6]['delivered_count']);
        $this->assertSame(1080.0, $byMonth[6]['delivered_value']);

        // July cohort: two placed, one delivered so far
        $this->assertSame(2, $byMonth[7]['ordered_count']);
        $this->assertSame(2740.0, $byMonth[7]['ordered_value']);
        $this->assertSame(1, $byMonth[7]['delivered_count']);
        $this->assertSame(2160.0, $byMonth[7]['delivered_value']);
    }

    #[Test]
    public function revenue_by_category_groups_delivered_line_totals_by_month(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $saree = Category::query()->create([
            'name' => 'Saree',
            'slug' => 'saree',
            'is_active' => true,
        ]);
        $kurti = Category::query()->create([
            'name' => 'Kurti',
            'slug' => 'kurti',
            'is_active' => true,
        ]);

        $sareeProduct = Product::query()->create([
            'category_id' => $saree->id,
            'name' => 'Silk Saree',
            'slug' => 'silk-saree',
            'price' => 500,
            'purchase_price' => 400,
            'is_published' => true,
        ]);
        $kurtiProduct = Product::query()->create([
            'category_id' => $kurti->id,
            'name' => 'Cotton Kurti',
            'slug' => 'cotton-kurti',
            'price' => 500,
            'purchase_price' => 400,
            'is_published' => true,
        ]);

        $this->seedDeliveredOrder($courier, 'Ayesha', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00', productId: $sareeProduct->id);
        $this->seedDeliveredOrder($courier, 'Karim', 2160, 75, 30, 2, 400, '2026-07-20 12:00:00', productId: $kurtiProduct->id);
        $this->seedDeliveredOrder($courier, 'Nadia', 1580, 65, 21, 1, 400, '2026-08-05 11:00:00', productId: $sareeProduct->id);

        $report = app(AnalyticsService::class)->revenueByCategoryByMonth(2026);
        $byName = collect($report['categories'])->keyBy('name');

        $this->assertSame(1000.0, $byName['Saree']['total']);
        $this->assertSame(1000.0, $byName['Kurti']['total']);
        $this->assertSame(500.0, $byName['Saree']['values'][6]); // July index 6
        $this->assertSame(1000.0, $byName['Kurti']['values'][6]);
        $this->assertSame(500.0, $byName['Saree']['values'][7]); // August
        $this->assertSame(2000.0, $report['grand_total']);

        Livewire::test(AdminAnalyticsCategoryRevenue::class)
            ->set('year', 2026)
            ->assertSee('Revenue by category')
            ->assertSee('Saree')
            ->assertSee('Kurti')
            ->assertSee('Year total');
    }

    #[Test]
    public function month_breakdown_math_matches_direct_costs(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        // collected 1080, delivery 80 → COD = (1080-80)*1% = 10
        // cogs 400, pack 21, courier 65 → direct = 496, profit = 1080-496 = 584
        $this->seedDeliveredOrder($courier, 'Ayesha', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00');

        $breakdown = app(AnalyticsService::class)->monthBreakdown(2026, 7);

        $this->assertSame(1080.0, $breakdown['revenue']);
        $this->assertSame(400.0, $breakdown['direct_breakdown']['cogs']);
        $this->assertSame(21.0, $breakdown['direct_breakdown']['packaging']);
        $this->assertSame(65.0, $breakdown['direct_breakdown']['courier']);
        $this->assertSame(10.0, $breakdown['direct_breakdown']['cod']);
        $this->assertSame(496.0, $breakdown['direct']);
        $this->assertSame(0.0, $breakdown['indirect']);
        $this->assertSame(584.0, $breakdown['profit']);

        $money = $breakdown['money'];
        $this->assertSame(1080.0, $money['bill_to_customer']);
        $this->assertSame(1000.0, $money['product_price']);
        $this->assertSame(80.0, $money['customer_delivery']);
        $this->assertSame(1080.0, $money['remittance_base']);
        $this->assertSame(1005.0, $money['courier_receivable']); // 1080 - 65 - 10
        $this->assertSame(584.0, $money['gross_profit']); // 1005 - 400 - 21
    }

    #[Test]
    public function pnl_month_view_shows_money_stacks(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $this->seedDeliveredOrder($courier, 'Ayesha', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00');

        Livewire::test(AdminAnalyticsPnl::class)
            ->set('year', 2026)
            ->call('selectMonth', 7)
            ->assertSee('Bill to customer')
            ->assertSee('Receivable from courier')
            ->assertSee('Gross profit')
            ->assertSee('Product price')
            ->assertSee('Customer delivery');
    }

    #[Test]
    public function metric_detail_page_lists_orders(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $this->seedDeliveredOrder($courier, 'Detail Customer', 1080, 65, 21, 1, 400, '2026-07-15 10:00:00');

        Livewire::test(AdminAnalyticsDetail::class, [
            'year' => 2026,
            'month' => 7,
            'metric' => 'direct',
        ])
            ->assertSee('Direct cost')
            ->assertSee('Detail Customer')
            ->assertSee('COGS')
            ->assertSee('Packaging')
            ->assertSee('Profit & loss')
            ->assertSee('Analytics hub');
    }

    #[Test]
    public function analytics_routes_are_reachable_for_admin(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Analytics');

        $this->get(route('admin.analytics.pnl'))
            ->assertOk()
            ->assertSee('Profit & loss');

        $this->get(route('admin.analytics.ordered-delivered'))
            ->assertOk()
            ->assertSee('Ordered vs delivered');

        $this->get(route('admin.analytics.category-revenue'))
            ->assertOk()
            ->assertSee('Revenue by category');
    }
}
