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

    #[Test]
    public function official_raw_body_hmac_signature_is_accepted(): void
    {
        $order = $this->order();

        $payload = [
            'notification_type' => 'delivery_status',
            'consignment_id' => 12345,
            'invoice' => $order->order_number,
            'cod_amount' => 1500.0,
            'status' => 'delivered',
            'delivery_charge' => 100.0,
            'tracking_message' => 'Your package has been delivered successfully.',
            'updated_at' => '2023-03-02 12:45:30',
        ];

        $this->postWebhook($payload, [
            'X-Signature' => $this->sign($payload),
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertTrue(
            CourierData::query()
                ->where('order_id', $order->id)
                ->get()
                ->contains(fn (CourierData $row): bool => ($row->api_data['status'] ?? null) === 'delivered')
        );
    }

    #[Test]
    public function hmac_is_computed_against_the_untrimmed_raw_body(): void
    {
        $order = $this->order();

        $raw = ' {"notification_type":"tracking_update","invoice":"'.$order->order_number.'","tracking_message":"At last mile hub"} ';
        $signature = hash_hmac('sha256', $raw, 'secret-token');

        $this->call(
            'POST',
            '/api/steadfast/webhook',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer secret-token',
                'HTTP_X_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $raw,
        )->assertOk()->assertJson(['status' => 'success']);

        $this->assertTrue(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('note', 'At last mile hub')
                ->exists()
        );
    }

    #[Test]
    public function mismatched_x_signature_is_rejected(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Should not land',
        ], [
            'X-Signature' => str_repeat('a', 64),
        ])->assertUnauthorized();

        $this->assertSame(0, CourierData::query()->where('order_id', $order->id)->count());
        $this->assertFalse(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('note', 'Should not land')
                ->exists()
        );
    }

    #[Test]
    public function missing_x_signature_still_authenticates_with_bearer(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Picked up at warehouse',
        ])->assertOk();

        $this->assertSame(1, CourierData::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function duplicate_idempotency_key_is_not_processed_twice(): void
    {
        $order = $this->order();

        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Arrived at sorting center',
            'updated_at' => '2026-11-03 13:15:00',
        ];

        $this->postWebhook($payload, [
            'Idempotency-Key' => 'evt-delivery-1',
            'X-Signature' => $this->sign($payload),
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->postWebhook($payload, [
            'Idempotency-Key' => 'evt-delivery-1',
            'X-Signature' => $this->sign($payload),
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(1, CourierData::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OrderStatusHistory::query()->where('order_id', $order->id)->where('note', 'Arrived at sorting center')->count());
    }

    #[Test]
    public function distinct_idempotency_keys_are_processed_separately(): void
    {
        $order = $this->order();

        $first = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Left origin hub',
        ];
        $second = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Arrived destination hub',
        ];

        $this->postWebhook($first, ['Idempotency-Key' => 'evt-a'])->assertOk();
        $this->postWebhook($second, ['Idempotency-Key' => 'evt-b'])->assertOk();

        $this->assertSame(2, CourierData::query()->where('order_id', $order->id)->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), 'secret-token');
    }
}
