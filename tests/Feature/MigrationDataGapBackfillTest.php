<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MigrationDataGapBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_writing(): void
    {
        [$order, $line] = $this->seedGapOrder();

        Artisan::call('orders:backfill-migration-gaps', ['--dry-run' => true]);

        $this->assertSame(0.0, (float) $line->fresh()->purchase_price);
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount);
        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_backfills_purchase_price_and_settlement_from_collected(): void
    {
        [$order, $line] = $this->seedGapOrder();

        $exit = Artisan::call('orders:backfill-migration-gaps');

        $this->assertSame(0, $exit);
        $this->assertSame(400.0, (float) $line->fresh()->purchase_price);
        $this->assertSame(400.0, (float) $line->fresh()->unit_cost);

        $order->refresh();
        $this->assertSame(1500.0, (float) $order->paid_amount);
        $this->assertSame(1500.0, (float) $order->collected_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_settlement_backfill_is_idempotent(): void
    {
        [$order] = $this->seedGapOrder();

        Artisan::call('orders:backfill-migration-gaps');
        Artisan::call('orders:backfill-migration-gaps');

        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame(1500.0, (float) $order->fresh()->paid_amount);
    }

    public function test_estimate_courier_and_reclassify_unsettled_delivered(): void
    {
        $courier = Courier::query()->create([
            'name' => 'SteadFast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 60,
            'is_active' => true,
        ]);

        $settled = Order::query()->create([
            'order_number' => 'GAP-SETTLED',
            'name' => 'Buyer',
            'phone' => '01710000001',
            'address' => 'Addr',
            'city' => 'Dhaka',
            'subtotal' => 1000,
            'delivery_charge' => 120,
            'discount' => 0,
            'total' => 1120,
            'cod_amount' => 0,
            'collected_amount' => 1120,
            'paid_amount' => 1120,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cod',
            'status' => 'delivered',
            'courier_id' => $courier->id,
            'courier_charge' => 0,
            'placed_at' => now()->subDays(3),
            'actual_delivery_date' => now()->subDay(),
            'legacy_id' => 9001,
        ]);

        $unsettled = Order::query()->create([
            'order_number' => 'GAP-UNSETTLED',
            'name' => 'Buyer 2',
            'phone' => '01710000002',
            'address' => 'Addr',
            'city' => 'Khagrachari',
            'subtotal' => 1000,
            'delivery_charge' => 120,
            'discount' => 0,
            'total' => 1120,
            'cod_amount' => 1120,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 1120,
            'payment_status' => 'partial',
            'payment_method' => 'cod',
            'status' => 'delivered',
            'courier_id' => $courier->id,
            'courier_charge' => 0,
            'dispatch_date' => now()->subDays(2),
            'actual_delivery_date' => null,
            'placed_at' => now()->subDays(5),
            'legacy_id' => 9002,
        ]);

        Artisan::call('orders:backfill-migration-gaps', [
            '--skip-purchase-price' => true,
            '--skip-settlement' => true,
            '--estimate-courier' => true,
            '--reclassify-unsettled-delivered' => true,
        ]);

        $this->assertSame(60.0, (float) $settled->fresh()->courier_charge);
        $this->assertSame('dispatched', $unsettled->fresh()->status);
        $this->assertSame(60.0, (float) $unsettled->fresh()->courier_charge);
    }

    /**
     * @return array{0: Order, 1: OrderProduct}
     */
    private function seedGapOrder(): array
    {
        $product = Product::query()->create([
            'name' => 'Gap Necklace',
            'slug' => 'gap-necklace',
            'price' => 1500,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'GAP-1',
            'name' => 'Buyer',
            'phone' => '01710000000',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 1500,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 1500,
            'cod_amount' => 1500,
            'collected_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'payment_status' => 'partial',
            'payment_method' => 'cod',
            'status' => 'delivered',
            'placed_at' => now()->subMonth(),
            'actual_delivery_date' => now()->subWeeks(3),
            'legacy_id' => 8001,
        ]);

        $line = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 1500,
        ]);

        return [$order, $line];
    }
}
