<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\OrderDispatchService;
use App\Services\Couriers\SteadfastApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderTrackingReplaceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => '26766',
            'name' => 'Bidhan',
            'phone' => '01616244280',
            'address' => 'Pahartoli-Raozan',
            'city' => 'Chittagong',
            'status' => 'new',
            'subtotal' => 3120,
            'delivery_charge' => 0,
            'charge' => 0,
            'discount' => 0,
            'total' => 3120,
            'cod_amount' => 3120,
            'placed_at' => now(),
        ], $overrides));
    }

    public function test_order_with_existing_tracker_is_still_dispatchable(): void
    {
        $order = $this->order([
            'courier_tracker' => 'OLDTRACK123',
            'status' => 'new',
        ]);

        $this->assertTrue($order->isDispatchable());
    }

    public function test_manual_assign_replaces_existing_tracking_code(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_active' => true,
            'is_default' => true,
        ]);

        $order = $this->order([
            'courier_id' => $courier->id,
            'courier_tracker' => 'OLDTRACK123',
            'status' => 'dispatched',
            'dispatch_date' => now()->subHour(),
        ]);

        $updated = app(OrderDispatchService::class)->assignManual(
            $order,
            $courier->id,
            'NEWTRACK456',
        );

        $this->assertSame('NEWTRACK456', $updated->courier_tracker);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'note' => 'Tracking replaced via Steadfast. OLDTRACK123 → NEWTRACK456',
        ]);
    }

    public function test_api_dispatch_replaces_existing_tracking_code(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_active' => true,
            'is_default' => true,
        ]);

        $order = $this->order([
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFR_OLD_CODE',
            'courier_consignment_id' => '111',
            'status' => 'confirmed',
        ]);

        $steadfast = Mockery::mock(SteadfastApiClient::class);
        $steadfast->shouldReceive('createOrder')->once()->andReturn([
            'consignment' => [
                'consignment_id' => 270697676,
                'tracking_code' => 'SFR_NEW_CODE',
            ],
        ]);
        $this->app->instance(SteadfastApiClient::class, $steadfast);

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://example.test',
        ]);

        // CourierApiRegistry reads config — ensure steadfast is considered configured.
        $updated = app(OrderDispatchService::class)->dispatchViaApi($order, 'steadfast');

        $this->assertSame('SFR_NEW_CODE', $updated->courier_tracker);
        $this->assertSame('270697676', $updated->courier_consignment_id);
        $this->assertSame('dispatched', $updated->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'note' => 'Tracking replaced via Steadfast. SFR_OLD_CODE → SFR_NEW_CODE Parcel ID: 270697676',
        ]);
    }
}
