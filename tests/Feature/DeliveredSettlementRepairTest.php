<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderPaidStatusRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveredSettlementRepairTest extends TestCase
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

    #[Test]
    public function rebuilds_ledger_when_scalars_look_paid_but_due_is_wrong(): void
    {
        $courier = $this->steadfast();

        // Common migration leftover: collected=paid=total, but no payment_transactions → due stuck.
        $order = Order::query()->create([
            'order_number' => 'SCALAR-PAID',
            'name' => 'Scalar Settled',
            'phone' => '01710000010',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'is_replacement' => false,
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'charge' => 0,
            'discount' => 0,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'total' => 1080,
            'collected_amount' => 1080,
            'paid_amount' => 1080,
            'due_amount' => 1080,
            'cod_amount' => 1080,
            'payment_status' => 'partial',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(5),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

        $this->assertTrue(app(OrderPaidStatusRepairService::class)->needsRepair($order));

        $exit = Artisan::call('orders:repair-delivered-settlement');
        $this->assertSame(0, $exit);

        $order->refresh();
        $this->assertSame(1080.0, (float) $order->collected_amount);
        $this->assertSame(1080.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());

        $econ = app(AnalyticsService::class)->orderContribution($order->fresh(['items', 'courier']));
        $this->assertSame(1080.0, $econ['revenue']);
        $this->assertSame(10.0, $econ['cod']);
    }

    #[Test]
    public function tops_up_undercollected_delivered_non_exchange_to_bill_total(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'UNDER-COLL',
            'name' => 'Under',
            'phone' => '01710000011',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'is_replacement' => false,
            'subtotal' => 900,
            'delivery_charge' => 80,
            'total' => 980,
            'collected_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 480,
            'payment_status' => 'partial',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(3),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'method' => 'cod',
            'amount' => 500,
            'status' => 'completed',
            'kind' => 'settlement',
            'paid_at' => now()->subDay(),
            'external_id' => 'partial-'.$order->id,
        ]);

        app(OrderPaidStatusRepairService::class)->repairOrder($order);

        $order->refresh();
        $this->assertSame(980.0, (float) $order->collected_amount);
        $this->assertSame(980.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(2, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function skips_exchange_orders(): void
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'EXCH-1',
            'name' => 'Exchange',
            'phone' => '01710000012',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'is_replacement' => true,
            'subtotal' => 500,
            'delivery_charge' => 0,
            'total' => 500,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 500,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);

        $service = app(OrderPaidStatusRepairService::class);
        $this->assertFalse($service->needsRepair($order));
        $this->assertSame(0, $service->eligibleOrderCount());
    }

    #[Test]
    public function dry_run_does_not_write(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'DRY-1',
            'name' => 'Dry',
            'phone' => '01710000013',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'total' => 700,
            'collected_amount' => 700,
            'paid_amount' => 700,
            'due_amount' => 700,
            'payment_status' => 'partial',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDay(),
        ]);

        Artisan::call('orders:repair-delivered-settlement', ['--dry-run' => true]);

        $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame(700.0, (float) $order->fresh()->due_amount);
    }
}
