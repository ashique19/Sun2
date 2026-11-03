<?php

namespace Tests\Unit;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Services\Couriers\CourierTrackingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CourierTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CourierTrackingService
    {
        return app(CourierTrackingService::class);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function dhakaTimesProvider(): array
    {
        return [
            'naive webhook wall-clock' => ['2026-11-01 14:30:00', '01-Nov 14:30'],
            'iso T without offset is Dhaka not UTC' => ['2026-11-01T14:30:00', '01-Nov 14:30'],
            'iso T with milliseconds' => ['2026-11-01T14:30:00.000000', '01-Nov 14:30'],
            'explicit Dhaka offset' => ['2026-11-01T14:30:00+06:00', '01-Nov 14:30'],
            'explicit offset without colon' => ['2026-11-01T14:30:00+0600', '01-Nov 14:30'],
            'laravel UTC Z' => ['2026-11-01T14:30:00.000000Z', '01-Nov 20:30'],
            'short UTC Z' => ['2026-11-01T14:30:00Z', '01-Nov 20:30'],
            'utc carbon instant' => [Carbon::parse('2026-11-01 14:30:00', 'UTC'), '01-Nov 20:30'],
            'dhaka carbon instant' => [Carbon::parse('2026-11-01 14:30:00', 'Asia/Dhaka'), '01-Nov 14:30'],
            'unix seconds' => [1793521800, '01-Nov 14:30'],
            'unix seconds string' => ['1793521800', '01-Nov 14:30'],
        ];
    }

    #[Test]
    #[DataProvider('dhakaTimesProvider')]
    public function format_dhaka_keeps_courier_wall_clock_in_asia_dhaka(mixed $value, string $expected): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame($expected, $this->service()->formatDhaka($value));
    }

    #[Test]
    public function format_dhaka_returns_dash_for_empty_values(): void
    {
        $this->assertSame('—', $this->service()->formatDhaka(null));
        $this->assertSame('—', $this->service()->formatDhaka(''));
        $this->assertSame('—', $this->service()->formatDhaka('not-a-date'));
    }

    #[Test]
    public function loaded_logs_use_payload_time_not_utc_shifted_iso(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'TRK-TIME-1',
            'name' => 'Tracked Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1080,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF998877',
            'dispatch_date' => now(),
            'placed_at' => now()->subDay(),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'tracking_message' => 'Parcel picked up',
                'delivery_status' => 'in_review',
                'updated_at' => '2026-11-01T14:30:00',
            ],
            'created_at' => Carbon::parse('2026-11-01 08:30:00', 'UTC'),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'event' => 'order.delivered',
                'tracking_message' => 'Pathao delivered',
                'timestamp' => '2026-11-01 16:05:00',
            ],
            'created_at' => Carbon::parse('2026-11-01 10:05:00', 'UTC'),
        ]);

        $order->load('courierLogs');
        $events = $this->service()->eventsFromLoadedLogs($order);

        $this->assertSame('01-Nov 16:05', $events[0]['at']);
        $this->assertSame('Pathao delivered', $events[0]['message']);
        $this->assertSame('01-Nov 14:30', $events[1]['at']);
        $this->assertSame('Parcel picked up', $events[1]['message']);
    }

    #[Test]
    public function status_poll_snapshots_are_omitted_from_the_timeline(): void
    {
        $order = $this->trackedOrder();

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $order->courier_id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'source' => 'status_poll',
                'tracking_message' => 'Consignment status has been updated as Delivered',
                'status' => 'delivered',
                'delivery_status' => 'delivered',
                'updated_at' => '2026-11-04 01:00:00',
            ],
            'created_at' => Carbon::parse('2026-11-04 01:00:00', 'UTC'),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $order->courier_id,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Parcel received at Bogura hub',
                'updated_at' => '2026-11-03 13:15:00',
            ],
            'created_at' => Carbon::parse('2026-11-04 01:00:00', 'UTC'),
        ]);

        $events = $this->service()->eventsFromLoadedLogs($order->load('courierLogs'));

        $this->assertCount(1, $events);
        $this->assertSame('03-Nov 13:15', $events[0]['at']);
        $this->assertSame('Parcel received at Bogura hub', $events[0]['message']);
    }

    #[Test]
    public function payload_created_at_is_used_when_updated_at_is_missing(): void
    {
        $order = $this->trackedOrder();

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $order->courier_id,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Out for delivery',
                'created_at' => '2026-11-03 16:05:00',
            ],
            'created_at' => Carbon::parse('2026-11-04 01:00:00', 'UTC'),
        ]);

        $events = $this->service()->eventsFromLoadedLogs($order->load('courierLogs'));

        $this->assertCount(1, $events);
        $this->assertSame('03-Nov 16:05', $events[0]['at']);
        $this->assertSame('Out for delivery', $events[0]['message']);
    }

    #[Test]
    public function live_fetch_stores_tracking_step_time_not_poll_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 01:00:00', 'UTC'));

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/status_by_invoice/*' => Http::response([
                'delivery_status' => 'in_review',
            ], 200),
            'portal.packzy.com/api/v1/trackings_by_invoice/*' => Http::response([
                'status' => 200,
                'tracking' => [
                    [
                        'consignment_id' => 277193413,
                        'tracking_type' => 2,
                        'text' => 'Parcel received at Bogura hub.',
                        'created_at' => '2026-11-03 13:15:00',
                    ],
                    [
                        'consignment_id' => 277193413,
                        'tracking_type' => 3,
                        'text' => 'Out for delivery.',
                        'created_at' => '2026-11-03 16:05:00',
                    ],
                ],
            ], 200),
        ]);

        $order = $this->trackedOrder();

        $status = $this->service()->fetchAndRecordLiveStatus($order->load('courier'), backfillLegacy: false);

        $this->assertSame('in_review', $status);

        $events = $this->service()->eventsFromLoadedLogs($order->fresh()->load('courierLogs'));
        $messages = array_column($events, 'message');

        $this->assertSame('03-Nov 16:05', $events[0]['at']);
        $this->assertSame('Out for delivery.', $events[0]['message']);
        $this->assertSame('03-Nov 13:15', $events[1]['at']);
        $this->assertSame('Parcel received at Bogura hub.', $events[1]['message']);
        $this->assertFalse(in_array('Consignment status has been updated as In Review', $messages, true));
        $this->assertFalse(collect($events)->contains(fn (array $event): bool => str_contains($event['at'], '04-Nov')));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/trackings_by_invoice/'.$order->order_number));

        Carbon::setTestNow();
    }

    private function trackedOrder(): Order
    {
        $courier = Courier::query()->firstOrCreate(
            ['slug' => 'steadfast'],
            [
                'name' => 'Steadfast',
                'charge' => 60,
                'osd_charge' => 110,
                'cod_percentage' => 1,
                'is_active' => true,
            ],
        );

        return Order::query()->create([
            'order_number' => 'TRK-TIME-'.uniqid(),
            'name' => 'Tracked Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1080,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF998877',
            'courier_consignment_id' => '277193413',
            'dispatch_date' => now(),
            'placed_at' => now()->subDay(),
        ]);
    }
}
