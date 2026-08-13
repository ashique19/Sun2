<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderPaidStatusRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsPaidStatusRepairTest extends TestCase
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
    public function paid_status_repair_converts_to_delivered_and_records_payment(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'PAID-LEGACY-1',
            'name' => 'Legacy Paid',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'paid',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'total' => 1080,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 1080,
            'cod_amount' => 1080,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'placed_at' => now()->subDays(10),
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

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertDontSee('Repair payment received…')
            ->assertDontSee('Audit columns…')
            ->assertSee('Paid (legacy)');

        $result = app(OrderPaidStatusRepairService::class)->repairNextBatch(0, 100);
        $this->assertSame(1, $result['fixed_orders']);
        $this->assertSame(1, $result['payments_created']);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1080.0, (float) $order->collected_amount);
        $this->assertSame(1080.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->actual_delivery_date);

        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());

        // COD fee on analytics uses collected (Steadfast: (1080-80)*1% = 10).
        $econ = app(AnalyticsService::class)->orderContribution($order->fresh(['items', 'courier']));
        $this->assertSame(10.0, $econ['cod']);
        $this->assertSame(1080.0, $econ['revenue']);
    }

    #[Test]
    public function paid_repair_is_idempotent_and_skips_when_already_settled(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'PAID-LEGACY-2',
            'name' => 'Already Settled',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'Paid',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'total' => 500,
            'courier_id' => $courier->id,
            'placed_at' => now()->subDay(),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'method' => 'cod',
            'amount' => 500,
            'status' => 'completed',
            'kind' => 'settlement',
            'paid_at' => now(),
            'external_id' => 'preexisting-'.$order->id,
        ]);

        $service = app(OrderPaidStatusRepairService::class);
        $first = $service->repairNextBatch(0, 100);
        $this->assertSame(1, $first['fixed_orders']);
        $this->assertSame(0, $first['payments_created']);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(500.0, (float) $order->collected_amount);

        $this->assertSame(0, $service->eligibleOrderCount());
        $second = $service->repairNextBatch(0, 100);
        $this->assertSame(0, $second['scanned']);
        $this->assertTrue($second['done']);
    }

    #[Test]
    public function delivered_orders_missing_collection_get_order_total_as_payment_received(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'DEL-NO-COLLECT',
            'name' => 'Delivered Unsettled',
            'phone' => '01710000003',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 900,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 980,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 980,
            'cod_amount' => 980,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(5),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 900,
            'purchase_price' => 300,
            'unit_cost' => 300,
            'line_total' => 900,
        ]);

        $result = app(OrderPaidStatusRepairService::class)->repairNextBatch(0, 100);
        $this->assertSame(1, $result['fixed_orders']);
        $this->assertSame(1, $result['payments_created']);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(980.0, (float) $order->collected_amount);
        $this->assertSame(980.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame('paid', $order->payment_status);

        $econ = app(AnalyticsService::class)->orderContribution($order->fresh(['items', 'courier']));
        $this->assertSame(9.0, $econ['cod']); // (980-80)*1%
    }

    #[Test]
    public function delivered_with_collected_but_no_ledger_syncs_from_collected_amount(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'DEL-HAS-COLLECT',
            'name' => 'Ledger Gap',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 0,
            'total' => 500,
            'collected_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(3),
        ]);

        $result = app(OrderPaidStatusRepairService::class)->repairNextBatch(0, 100);

        $this->assertSame(1, $result['fixed_orders']);
        $this->assertSame(1, $result['payments_created']);

        $order->refresh();
        $this->assertSame(500.0, (float) $order->collected_amount);
        $this->assertSame(500.0, (float) $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(0, app(OrderPaidStatusRepairService::class)->eligibleOrderCount());
    }
}
