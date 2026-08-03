<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\SteadfastWebhookInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardSteadfastWebhooksTest extends TestCase
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
        return Courier::query()->firstOrCreate(
            ['slug' => 'steadfast'],
            [
                'name' => 'Steadfast',
                'charge' => 60,
                'osd_charge' => 110,
                'cod_percentage' => 1,
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }

    private function dispatchedOrder(string $suffix = '1', ?string $consignmentId = null): Order
    {
        $courier = $this->steadfast();
        $digits = preg_replace('/\D+/', '', $suffix) ?: (string) random_int(10, 99);

        return Order::query()->create([
            'order_number' => 'WH-'.$suffix.'-'.uniqid(),
            'name' => 'Webhook Customer '.$suffix,
            'phone' => '017100000'.substr(md5($suffix), 0, 2),
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1000,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFR_'.$suffix,
            'courier_consignment_id' => $consignmentId ?? ('2706976'.$digits),
            'placed_at' => now()->subDay(),
            'dispatch_date' => now()->subDay(),
        ]);
    }

    #[Test]
    public function dashboard_lists_latest_webhook_per_order_with_order_and_parcel_links(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder('A', '270697681');

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Older tracking hop',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subHours(3),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'status' => 'in_transit',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subHour(),
        ]);

        $parcelUrl = 'https://steadfast.com.bd/user/consignment/270697681';

        Livewire::test(AdminDashboard::class)
            ->assertSee('Latest Steadfast webhooks')
            ->assertSee('Order #'.$order->order_number)
            ->assertSee($order->name)
            ->assertSee('in_transit')
            ->assertDontSee('Older tracking hop')
            ->assertSee(route('admin.orders.show', $order), false)
            ->assertSee($parcelUrl, false)
            ->assertSee('Parcel '.$order->courier_consignment_id);
    }

    #[Test]
    public function inbox_ignores_old_poll_and_dispatch_payloads_and_caps_at_twenty(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $service = app(SteadfastWebhookInboxService::class);

        $stale = $this->dispatchedOrder('OLD');
        CourierData::query()->create([
            'order_id' => $stale->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'status' => 'delivered',
            ],
            'created_at' => now()->subDays(3),
        ]);

        $polled = $this->dispatchedOrder('POLL');
        CourierData::query()->create([
            'order_id' => $polled->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'source' => 'status_poll',
                'status' => 'delivered',
            ],
            'created_at' => now()->subHour(),
        ]);

        $dispatchOnly = $this->dispatchedOrder('API');
        CourierData::query()->create([
            'order_id' => $dispatchOnly->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'tracking_code' => 'SFR_API',
                'consignment_id' => 123,
            ],
            'created_at' => now()->subHour(),
        ]);

        $kept = [];
        for ($i = 1; $i <= 22; $i++) {
            $order = $this->dispatchedOrder((string) $i);
            $kept[] = $order->order_number;
            CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courier->id,
                'api_data' => [
                    'notification_type' => 'delivery_status',
                    'status' => 'pending',
                    'invoice' => $order->order_number,
                ],
                'created_at' => now()->subMinutes(22 - $i),
            ]);
        }

        $entries = $service->latestIncoming();

        $this->assertCount(20, $entries);
        $this->assertTrue($entries->every(fn (CourierData $row) => filled(data_get($row->api_data, 'notification_type'))));
        $this->assertFalse($entries->contains(fn (CourierData $row) => $row->order_id === $stale->id));
        $this->assertFalse($entries->contains(fn (CourierData $row) => $row->order_id === $polled->id));
        $this->assertFalse($entries->contains(fn (CourierData $row) => $row->order_id === $dispatchOnly->id));

        Livewire::test(AdminDashboard::class)
            ->assertSee('Latest Steadfast webhooks')
            ->assertDontSee('Order #'.$stale->order_number)
            ->assertDontSee('Order #'.$polled->order_number)
            ->assertSee('Order #'.$kept[21])
            ->assertDontSee('Order #'.$kept[0]);
    }

    #[Test]
    public function empty_inbox_hides_the_dashboard_section(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertDontSee('Latest Steadfast webhooks');
    }
}
