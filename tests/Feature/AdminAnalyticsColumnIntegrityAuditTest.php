<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderCalculationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAnalyticsColumnIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function audit_is_report_only_and_flags_missing_cogs_and_delivered_zero_revenue(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'No Cost Product',
            'slug' => 'no-cost-product',
            'sku' => 'NCP-1',
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'is_published' => true,
        ]);

        $missingCogs = Order::query()->create([
            'order_number' => 'AUD-NO-COGS',
            'name' => 'No Cogs',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $missingCogs->id,
            'product_id' => $product->id,
            'name' => 'No Cost Product',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $deliveredZeroRevenue = Order::query()->create([
            'order_number' => 'AUD-ZERO-REV',
            'name' => 'Zero Rev',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1080,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'collected_amount' => 0,
            'courier_id' => $courier->id,
            'placed_at' => '2024-06-15 10:00:00',
            'actual_delivery_date' => '2024-06-20 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $deliveredZeroRevenue->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

        $audit = app(OrderCalculationAuditService::class);
        $this->assertSame(2, $audit->eligibleOrderCount());
        $this->assertSame(1, $audit->eligibleOrderCount(2024));
        $result = $audit->auditNextBatch(0, 100, 2024);
        $this->assertSame(1, $result['manual_needed']);
        $issueText = collect($result['issues'])->pluck('order_number')->implode(' ');
        $this->assertStringContainsString('AUD-ZERO-REV', $issueText);
        $messages = collect($result['issues'])->flatMap(fn ($row) => $row['issues'])->implode(' ');
        $this->assertStringContainsString('collected_amount missing', $messages);

        // Report-only: packaging/COGS were not mutated.
        $missingCogs->refresh();
        $this->assertSame(0.0, (float) $missingCogs->fresh('items')->items->first()->unit_cost);
    }

    #[Test]
    public function audit_flags_unreadable_dates_that_break_year_analytics(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'BAD-DATE',
            'name' => 'Bad Date',
            'phone' => '01710000003',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'collected_amount' => 500,
            'courier_id' => $courier->id,
            'placed_at' => '2023-01-15 10:00:00',
            'actual_delivery_date' => '2023-01-20 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        // Bypass Eloquent cast: store a legacy zero date that year pages used to choke on.
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['placed_at' => '0000-00-00 00:00:00']);

        $order = $order->fresh(['items', 'courier']);
        $result = app(OrderCalculationAuditService::class)->auditOrder($order);

        $this->assertFalse($result['auto_fixed']);
        $this->assertNotEmpty($result['issues']);
        $this->assertTrue(
            collect($result['issues'])->contains(fn (string $issue) => str_contains($issue, 'Unreadable placed_at'))
        );
    }

    #[Test]
    public function available_years_skips_unreadable_dates_without_throwing(): void
    {
        Order::query()->create([
            'order_number' => 'OK-YEAR',
            'name' => 'Ok',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 100,
            'total' => 100,
            'placed_at' => '2022-05-01 10:00:00',
            'created_at' => '2022-05-01 10:00:00',
            'updated_at' => '2022-05-01 10:00:00',
        ]);

        $bad = Order::query()->create([
            'order_number' => 'BAD-YEAR',
            'name' => 'Bad',
            'phone' => '01710000005',
            'address' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 100,
            'total' => 100,
            'placed_at' => '2022-05-02 10:00:00',
            'created_at' => '2022-05-02 10:00:00',
            'updated_at' => '2022-05-02 10:00:00',
        ]);

        DB::table('orders')
            ->where('id', $bad->id)
            ->update(['created_at' => '0000-00-00 00:00:00']);

        $years = app(AnalyticsService::class)->availableYears();

        $this->assertContains(2022, $years);
        $this->assertContains((int) now('Asia/Dhaka')->year, $years);
    }

    #[Test]
    public function clean_order_has_no_column_issues(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'CLEAN-1',
            'name' => 'Clean',
            'phone' => '01710000006',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 580,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'collected_amount' => 580,
            'paid_amount' => 580,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'courier_id' => $courier->id,
            'placed_at' => '2025-02-01 10:00:00',
            'actual_delivery_date' => '2025-02-05 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 180,
            'unit_cost' => 180,
            'line_total' => 500,
        ]);

        $result = app(OrderCalculationAuditService::class)->auditOrder($order->fresh(['items', 'courier']));

        $this->assertSame([], $result['issues']);
    }
}
