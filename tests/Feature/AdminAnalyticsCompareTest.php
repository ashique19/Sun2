<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
use App\Livewire\Admin\AdminAnalyticsCompare;
use App\Models\Courier;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\AnalyticsYearCompareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsCompareTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function seedDelivered(string $createdAt, float $collected, float $cogs = 200): void
    {
        $courier = Courier::query()->first() ?? Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'CMP-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000000',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => $collected - 80,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'collected_amount' => $collected,
            'total' => $collected,
            'courier_id' => $courier->id,
            'placed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => $cogs,
            'unit_cost' => $cogs,
            'line_total' => 500,
        ]);
    }

    #[Test]
    public function compare_page_renders_and_switches_metrics(): void
    {
        $this->actingAs($this->adminUser());
        $this->seedDelivered('2026-07-15 10:00:00', 1080);

        Livewire::test(AdminAnalyticsCompare::class)
            ->assertSee('Compare years')
            ->assertSee('Profit / loss')
            ->assertSeeHtml('data-compare-metric="profit"')
            ->assertSeeHtml('data-year-total="2026"')
            ->set('metric', 'ordered_count')
            ->assertSet('metric', 'ordered_count')
            ->assertSee('Orders placed');
    }

    #[Test]
    public function compare_service_builds_ten_year_month_series(): void
    {
        $this->seedDelivered('2025-03-10 10:00:00', 1000, 100);
        $this->seedDelivered('2026-03-10 10:00:00', 2000, 100);
        Expense::query()->create([
            'title' => 'Rent',
            'category' => 'rent',
            'kind' => Expense::KIND_ONE_TIME,
            'amount' => 50,
            'spent_on' => '2026-03-01',
        ]);

        $chart = app(AnalyticsYearCompareService::class)->compare('profit', 2026);

        $this->assertSame('profit', $chart['metric']);
        $this->assertCount(AnalyticsYearCompareService::YEAR_COUNT, $chart['years']);
        $this->assertSame(2017, $chart['years'][0]);
        $this->assertSame(2026, $chart['years'][9]);
        $this->assertCount(12, $chart['labels']);
        $this->assertCount(10, $chart['series']);

        $series2026 = collect($chart['series'])->firstWhere('label', '2026');
        $this->assertNotNull($series2026);
        // Mar index 2 — collected 2000 - cogs 100 - pack 21 - courier 60 - COD (2000-80)*1% - expense 50
        $expected = 2000 - 100 - 21 - 60 - 19.2 - 50;
        $this->assertEqualsWithDelta($expected, $series2026['values'][2], 0.01);

        $ordered = app(AnalyticsYearCompareService::class)->compare('ordered_count', 2026);
        $series2025 = collect($ordered['series'])->firstWhere('label', '2025');
        $this->assertSame(1.0, $series2025['values'][2]);
    }

    #[Test]
    public function analytics_hub_and_route_expose_compare(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalytics::class)
            ->assertSee('Compare years');

        $this->get(route('admin.analytics.compare'))
            ->assertOk()
            ->assertSee('Compare years');
    }
}
