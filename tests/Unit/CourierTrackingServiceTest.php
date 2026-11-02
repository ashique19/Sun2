<?php

namespace Tests\Unit;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Services\Couriers\CourierTrackingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
