<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
use App\Livewire\Admin\AdminAnalyticsDetail;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
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
            'name' => 'Product',
            'quantity' => $qty,
            'price' => 500,
            'purchase_price' => $purchasePrice,
            'line_total' => 500 * $qty,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function analytics_nav_page_shows_year_donut_and_drills_into_month(): void
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

        Livewire::test(AdminAnalytics::class)
            ->set('year', 2026)
            ->assertSee('Analytics')
            ->assertSee('Months in 2026')
            ->assertSee('Ordered vs delivered')
            ->assertSee('Order count')
            ->assertSee('Order value')
            ->assertSee('Jul')
            ->assertSee('Pick a month below')
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
    public function ordered_vs_delivered_splits_by_placement_and_delivery_month(): void
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

        $report = app(AnalyticsService::class)->orderedVsDeliveredByMonth(2026);
        $byMonth = collect($report['months'])->keyBy('month');

        $this->assertSame(1, $byMonth[6]['ordered_count']);
        $this->assertSame(1080.0, $byMonth[6]['ordered_value']);
        $this->assertSame(0, $byMonth[6]['delivered_count']);

        $this->assertSame(1, $byMonth[7]['ordered_count']);
        $this->assertSame(2160.0, $byMonth[7]['ordered_value']);
        $this->assertSame(2, $byMonth[7]['delivered_count']);
        $this->assertSame(3240.0, $byMonth[7]['delivered_value']);
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
            ->assertSee('Packaging');
    }

    #[Test]
    public function analytics_route_is_reachable_for_admin(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Analytics');
    }
}
