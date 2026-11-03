<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Couriers\CourierTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastWebhookIngestTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        Courier::query()->firstOrCreate(
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

        return Order::query()->create(array_merge([
            'order_number' => 'ORD-TRACK-1',
            'name' => 'Customer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1200,
            'due_amount' => 1200,
            'cod_amount' => 1200,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'courier_tracker' => '15A4B80AA',
            'courier_consignment_id' => '277193413',
            'is_replacement' => false,
            'has_return' => false,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postWebhook(array $payload, array $headers = []): TestResponse
    {
        return $this->postJson('/api/steadfast/webhook', $payload, array_merge([
            'Authorization' => 'Bearer secret-token',
        ], $headers));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);
    }

    #[Test]
    public function tracking_update_without_notification_type_is_stored_and_shown(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'consignment_id' => 277193413,
            'invoice' => $order->order_number,
            'tracking_message' => 'Arrived at Bogura hub',
            'updated_at' => '2026-11-03 13:15:00',
        ])->assertOk()->assertJson(['status' => 'success']);

        $stored = CourierData::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($stored);
        $this->assertSame('tracking_update', $stored->api_data['notification_type'] ?? null);
        $this->assertSame('Arrived at Bogura hub', $stored->api_data['tracking_message'] ?? null);

        $this->assertTrue(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('note', 'Arrived at Bogura hub')
                ->exists()
        );

        $events = app(CourierTrackingService::class)->trackingEvents($order->fresh());
        $this->assertTrue(collect($events)->contains(
            fn (array $event): bool => $event['message'] === 'Arrived at Bogura hub'
        ));
    }

    #[Test]
    public function nested_data_wrapper_tracking_update_is_processed(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'status' => 200,
            'data' => [
                'notification_type' => 'tracking_update',
                'consignment_id' => 277193413,
                'invoice' => $order->order_number,
                'tracking_message' => 'Out for delivery',
                'updated_at' => '2026-11-03 14:00:00',
            ],
        ])->assertOk();

        $this->assertTrue(
            CourierData::query()
                ->where('order_id', $order->id)
                ->get()
                ->contains(fn (CourierData $row): bool => ($row->api_data['tracking_message'] ?? null) === 'Out for delivery')
        );
    }

    #[Test]
    public function note_field_is_accepted_as_the_tracking_message(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'note' => 'Rider assigned',
            'updated_at' => '2026-11-03 15:00:00',
        ])->assertOk();

        $events = app(CourierTrackingService::class)->trackingEvents($order->fresh());
        $this->assertTrue(collect($events)->contains(
            fn (array $event): bool => $event['message'] === 'Rider assigned'
        ));
    }

    #[Test]
    public function json_body_without_json_content_type_is_still_read(): void
    {
        $order = $this->order();

        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'At last mile hub',
            'updated_at' => '2026-11-03 16:00:00',
        ];

        $this->call(
            'POST',
            '/api/steadfast/webhook',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer secret-token',
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($payload),
        )->assertOk()->assertJson(['status' => 'success']);

        $this->assertTrue(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('note', 'At last mile hub')
                ->exists()
        );
    }

    #[Test]
    public function lowercase_bearer_prefix_is_accepted(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Picked up',
        ], [
            'Authorization' => 'bearer secret-token',
        ])->assertOk();

        $this->assertSame(1, CourierData::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function empty_payload_is_acknowledged_instead_of_rejected(): void
    {
        $this->postWebhook([])->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(0, CourierData::query()->count());
    }

    #[Test]
    public function unauthorized_requests_still_fail(): void
    {
        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'tracking_update',
            'tracking_message' => 'Nope',
        ])->assertUnauthorized();
    }

    #[Test]
    public function finds_order_by_numeric_tracking_id_matching_consignment(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'tracking_id' => 277193413,
            'tracking_message' => 'Reached destination city',
        ])->assertOk();

        $this->assertTrue(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('note', 'Reached destination city')
                ->exists()
        );
    }
}
