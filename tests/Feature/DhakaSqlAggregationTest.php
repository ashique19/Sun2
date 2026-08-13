<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Admin\AnalyticsService;
use App\Support\AdminDashboardMetrics;
use App\Support\DhakaSql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DhakaSqlAggregationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dhaka_date_expression_shifts_utc_into_asia_dhaka_calendar_day(): void
    {
        // 2026-07-31 20:00 UTC == 2026-08-01 02:00 Asia/Dhaka
        $row = DB::selectOne(
            'select '.DhakaSql::date('?').' as day',
            ['2026-07-31 20:00:00']
        );

        $this->assertSame('2026-08-01', (string) $row->day);
    }

    #[Test]
    public function dashboard_activity_aggregates_in_sql_without_loading_order_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'Asia/Dhaka'));

        $placed = Carbon::parse('2026-08-12 10:00:00', 'Asia/Dhaka')->utc();

        for ($i = 0; $i < 3; $i++) {
            Order::query()->create([
                'order_number' => 'SQL-'.$i,
                'name' => 'Customer',
                'phone' => '0171000000'.$i,
                'address' => 'Dhaka',
                'status' => $i === 0 ? 'delivered' : 'new',
                'subtotal' => 1000,
                'total' => 1000,
                'paid_amount' => $i === 0 ? 900 : 0,
                'collected_amount' => $i === 0 ? 900 : 0,
                'placed_at' => $placed->copy()->addMinutes($i),
                'placed_via' => Order::PLACED_VIA_ADMIN,
            ]);
        }

        DB::enableQueryLog();
        $activity = AdminDashboardMetrics::orderActivity(fresh: true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $selects = collect($queries)->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'select')
                && str_contains(strtolower($query['query']), 'from "orders"')
        );

        $this->assertTrue(
            $selects->contains(fn (array $query) => str_contains(strtolower($query['query']), 'group by')),
            'Expected dashboard activity to group in SQL.'
        );

        $day = collect($activity['last7']['days'])->firstWhere('date', '2026-08-12');
        $this->assertNotNull($day);
        $this->assertSame(3, $day['order_qty']);
        $this->assertSame(1, $day['delivery_qty']);
        $this->assertSame(900.0, $day['delivery_value']);
    }

    #[Test]
    public function year_overview_groups_collected_revenue_by_dhaka_month(): void
    {
        Order::query()->create([
            'order_number' => 'YO-1',
            'name' => 'Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'collected_amount' => 500,
            'placed_at' => '2026-03-10 10:00:00',
            'created_at' => '2026-03-10 10:00:00',
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        Order::query()->create([
            'order_number' => 'YO-2',
            'name' => 'Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 700,
            'total' => 700,
            'collected_amount' => 700,
            'placed_at' => '2026-03-20 10:00:00',
            'created_at' => '2026-03-20 10:00:00',
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        $overview = app(AnalyticsService::class)->yearOverview(2026);

        $this->assertSame(1200.0, $overview['revenue']);
        $this->assertSame(2, $overview['order_count']);
        $this->assertSame(1200.0, $overview['months'][2]['revenue']);
        $this->assertSame(2, $overview['months'][2]['order_count']);
    }
}
