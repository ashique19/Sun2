<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersTrackingPanelTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function orderWithTracking(Courier $courier, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'TRK-'.uniqid(),
            'name' => 'Tracked Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 0,
            'collected_amount' => 1080,
            'payment_status' => 'paid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF998877',
            'courier_consignment_id' => '288776655',
            'dispatch_date' => now()->subDays(2),
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(3),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Parcel delivered to customer',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subDay(),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'status' => 'delivered',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subHours(20),
        ]);

        return $order;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function trackingSegmentsProvider(): array
    {
        return [
            'delivered' => ['delivered', [
                'status' => 'delivered',
                'actual_delivery_date' => now()->subDay(),
                'collected_amount' => 1080,
            ]],
            'cancel-return' => ['cancel-return', [
                'status' => 'returned',
                'collected_amount' => 0,
                'due_amount' => 0,
            ]],
            'return-pending' => ['return-pending', [
                'status' => 'delivered',
                'has_return' => true,
                'actual_delivery_date' => now()->subDay(),
                'collected_amount' => 1080,
            ]],
        ];
    }

    #[Test]
    #[DataProvider('trackingSegmentsProvider')]
    public function terminal_segments_show_stored_tracking_timeline(string $segment, array $overrides): void
    {
        $this->actingAs($this->adminUser());
        $this->orderWithTracking($this->steadfast(), $overrides);

        Livewire::test(AdminOrders::class, ['segment' => $segment])
            ->assertSet('segment', $segment)
            ->assertSee('Steadfast ↗')
            ->assertSee('SF998877')
            ->assertSee('Parcel delivered to customer')
            ->assertSee('DELIVERED')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/288776655"')
            ->assertDontSeeHtml('wire:click="refreshCourierStatuses"');
    }

    #[Test]
    public function new_segment_does_not_show_tracking_timeline(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Order::query()->create([
            'order_number' => 'NEW-'.uniqid(),
            'name' => 'New Customer',
            'phone' => '01710000088',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertDontSee('No tracking updates yet.')
            ->assertDontSeeHtml('order-tracking-');
    }

    #[Test]
    public function shows_courier_tracking_helper_covers_terminal_segments(): void
    {
        $this->assertTrue(AdminOrderSegment::showsCourierTracking('dispatched'));
        $this->assertTrue(AdminOrderSegment::showsCourierTracking('delivered'));
        $this->assertTrue(AdminOrderSegment::showsCourierTracking('cancel-return'));
        $this->assertTrue(AdminOrderSegment::showsCourierTracking('return-pending'));
        $this->assertFalse(AdminOrderSegment::showsCourierTracking('new'));
        $this->assertFalse(AdminOrderSegment::showsCourierTracking('all'));
    }
}
