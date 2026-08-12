<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\OrderPackagingCourierRepairService;
use App\Services\Orders\OrderCourierChargeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsPackagingCourierRepairTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function courier(string $slug = 'steadfast'): Courier
    {
        return Courier::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => $slug === 'redx' ? 0 : 1,
            'is_active' => true,
            'is_default' => $slug === 'steadfast',
        ]);
    }

    #[Test]
    public function logistics_repair_fills_zero_packaging_and_courier(): void
    {
        $this->actingAs($this->adminUser());
        $steadfast = $this->courier('steadfast');
        $redx = $this->courier('redx');

        $packZero = Order::query()->create([
            'order_number' => 'LOG-PACK',
            'name' => 'Pack',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 0,
            'courier_charge' => 60,
            'courier_id' => $steadfast->id,
            'collected_amount' => 500,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $packZero->id,
            'name' => 'Kurti',
            'quantity' => 2,
            'price' => 250,
            'purchase_price' => 100,
            'line_total' => 500,
        ]);

        $courierZero = Order::query()->create([
            'order_number' => 'LOG-COURIER',
            'name' => 'Courier',
            'phone' => '01710000002',
            'address' => 'Sylhet',
            'city' => 'Sylhet',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 0,
            'courier_id' => $redx->id,
            'collected_amount' => 500,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $courierZero->id,
            'name' => 'Kurti',
            'quantity' => 3,
            'price' => 200,
            'purchase_price' => 80,
            'line_total' => 600,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertDontSee('Repair packaging / courier…')
            ->assertSee('Audit columns…');

        $result = app(OrderPackagingCourierRepairService::class)->repairNextBatch(0, 100);
        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['packaging_fixed']);
        $this->assertSame(1, $result['courier_fixed']);

        $packZero->refresh();
        $courierZero->refresh();

        // 2025+: 2 pcs → 21+11=32
        $this->assertSame(32.0, (float) $packZero->packaging_cost);
        // RedX outside Dhaka, 3 pcs → 100+10+10=120
        $this->assertSame(120.0, (float) $courierZero->courier_charge);
    }

    #[Test]
    public function piece_based_courier_rates_match_redx_and_others(): void
    {
        $sync = app(OrderCourierChargeSync::class);
        $steadfast = $this->courier('steadfast');
        $redx = $this->courier('redx');

        $dhaka = Order::query()->create([
            'order_number' => 'FEE-D',
            'name' => 'D',
            'phone' => '017',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 100,
            'total' => 100,
            'courier_id' => $steadfast->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $dhaka->id,
            'name' => 'Item',
            'quantity' => 2,
            'price' => 50,
            'purchase_price' => 10,
            'line_total' => 100,
        ]);

        $this->assertSame(70.0, $sync->estimateMerchantDeliveryFee($dhaka->fresh(['items']), $steadfast));

        $outsideRedx = Order::query()->create([
            'order_number' => 'FEE-R',
            'name' => 'R',
            'phone' => '017',
            'address' => 'Bogura',
            'city' => 'Bogura',
            'status' => 'dispatched',
            'subtotal' => 100,
            'total' => 100,
            'courier_id' => $redx->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $outsideRedx->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 100,
            'purchase_price' => 10,
            'line_total' => 100,
        ]);

        $this->assertSame(100.0, $sync->estimateMerchantDeliveryFee($outsideRedx->fresh(['items']), $redx));
    }

    #[Test]
    public function repair_service_skips_orders_that_already_have_both_costs(): void
    {
        $courier = $this->courier();
        Order::query()->create([
            'order_number' => 'OK-1',
            'name' => 'Ok',
            'phone' => '017',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 100,
            'total' => 100,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);

        $result = app(OrderPackagingCourierRepairService::class)->repairNextBatch(0, 100);

        $this->assertSame(0, $result['scanned']);
        $this->assertTrue($result['done']);
        $this->assertSame(0, app(OrderPackagingCourierRepairService::class)->eligibleOrderCount());
    }
}
