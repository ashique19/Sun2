<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersSummarizePerformanceTest extends TestCase
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
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function deliveredOrder(Courier $courier, int $n): Order
    {
        $order = Order::query()->create([
            'order_number' => 'PERF-'.$n,
            'name' => 'Customer '.$n,
            'phone' => '01710000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item '.$n,
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        return $order;
    }

    #[Test]
    public function summarize_matches_prior_math_without_loading_line_items(): void
    {
        $courier = $this->steadfast();

        Order::query()->create([
            'order_number' => 'PEND-1',
            'name' => 'Pending Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'cod_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        $this->deliveredOrder($courier, 1);

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'withdraw',
            'amount' => -200,
            'balance_after' => 0,
            'note' => 'Partial remittance',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = app(CourierBalanceService::class)->summarize($courier->fresh());

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertSame(1080.0, $summary['pending']);
        $this->assertSame(810.0, $summary['receivable']);
        $this->assertSame(200.0, $summary['withdrawn']);
        $this->assertStringNotContainsStringIgnoringCase('order_products', $sql);
        $this->assertStringNotContainsStringIgnoringCase('order_adjustments', $sql);
    }

    #[Test]
    public function couriers_page_loads_with_many_delivered_orders_without_item_queries(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        for ($i = 1; $i <= 25; $i++) {
            $this->deliveredOrder($courier, $i);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(AdminCouriers::class)
            ->assertSee('Receivable')
            ->assertSee('Steadfast')
            ->assertSee('Refresh API')
            ->assertDontSeeHtml('wire:init="loadApiBalances"');

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsStringIgnoringCase('order_products', $sql);
        $this->assertStringNotContainsStringIgnoringCase('order_adjustments', $sql);
    }
}
