<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\SteadfastWebhookLog;
use App\Services\Couriers\SteadfastWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastWebhookLogTest extends TestCase
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
            'order_number' => 'ORD-LOG-1',
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
    public function processed_webhook_is_logged_with_raw_body_and_order(): void
    {
        $order = $this->order();

        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'consignment_id' => 277193413,
            'tracking_message' => 'Arrived at Bogura hub',
            'updated_at' => '2026-11-03 13:15:00',
        ];

        $this->postWebhook($payload, [
            'Idempotency-Key' => 'evt-log-1',
            'X-Signature' => $this->sign($payload),
        ])->assertOk();

        $log = SteadfastWebhookLog::query()->sole();

        $this->assertSame(SteadfastWebhookLog::OUTCOME_PROCESSED, $log->outcome);
        $this->assertSame(200, $log->http_status);
        $this->assertSame($order->id, $log->order_id);
        $this->assertSame('tracking_update', $log->notification_type);
        $this->assertSame($order->order_number, $log->invoice);
        $this->assertSame('277193413', $log->consignment_id);
        $this->assertSame('evt-log-1', $log->idempotency_key);
        $this->assertTrue($log->has_signature);
        $this->assertStringContainsString('Arrived at Bogura hub', (string) $log->raw_body);
        $this->assertSame('Arrived at Bogura hub', $log->payload['tracking_message'] ?? null);
        $this->assertNull($log->error);
    }

    #[Test]
    public function unmatched_webhook_is_acknowledged_and_logged(): void
    {
        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => 'MISSING-ORDER',
            'consignment_id' => 111,
            'tracking_message' => 'No such parcel',
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(0, CourierData::query()->count());

        $log = SteadfastWebhookLog::query()->sole();
        $this->assertSame(SteadfastWebhookLog::OUTCOME_UNMATCHED, $log->outcome);
        $this->assertSame(200, $log->http_status);
        $this->assertNull($log->order_id);
        $this->assertSame('MISSING-ORDER', $log->invoice);
        $this->assertSame('111', $log->consignment_id);
        $this->assertStringContainsString('No such parcel', (string) $log->raw_body);
    }

    #[Test]
    public function empty_payload_is_logged_as_empty(): void
    {
        $this->postWebhook([])->assertOk();

        $log = SteadfastWebhookLog::query()->sole();
        $this->assertSame(SteadfastWebhookLog::OUTCOME_EMPTY, $log->outcome);
        $this->assertSame(200, $log->http_status);
        $this->assertNull($log->order_id);
        $this->assertSame('[]', $log->raw_body);
    }

    #[Test]
    public function duplicate_idempotency_key_is_logged_separately(): void
    {
        $order = $this->order();
        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Arrived at sorting center',
        ];

        $this->postWebhook($payload, ['Idempotency-Key' => 'evt-dup'])->assertOk();
        $this->postWebhook($payload, ['Idempotency-Key' => 'evt-dup'])->assertOk();

        $this->assertSame(1, CourierData::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, SteadfastWebhookLog::query()->count());
        $this->assertEqualsCanonicalizing(
            [SteadfastWebhookLog::OUTCOME_PROCESSED, SteadfastWebhookLog::OUTCOME_DUPLICATE],
            SteadfastWebhookLog::query()->orderBy('id')->pluck('outcome')->all(),
        );
    }

    #[Test]
    public function unauthorized_requests_are_not_logged(): void
    {
        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'tracking_update',
            'tracking_message' => 'Nope',
        ])->assertUnauthorized();

        $this->assertSame(0, SteadfastWebhookLog::query()->count());
    }

    #[Test]
    public function mismatched_signature_is_not_logged(): void
    {
        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => 'ORD-LOG-1',
            'tracking_message' => 'Should not land',
        ], [
            'X-Signature' => str_repeat('a', 64),
        ])->assertUnauthorized();

        $this->assertSame(0, SteadfastWebhookLog::query()->count());
    }

    #[Test]
    public function processing_failure_is_logged_and_returns_server_error(): void
    {
        $this->mock(SteadfastWebhookProcessor::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('boom'));
        });

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => 'ORD-FAIL',
            'tracking_message' => 'Will fail',
        ])->assertStatus(500);

        $log = SteadfastWebhookLog::query()->sole();
        $this->assertSame(SteadfastWebhookLog::OUTCOME_FAILED, $log->outcome);
        $this->assertSame(500, $log->http_status);
        $this->assertSame('boom', $log->error);
        $this->assertSame('ORD-FAIL', $log->invoice);
    }

    #[Test]
    public function authorization_header_is_not_stored_in_the_log(): void
    {
        $order = $this->order();

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_message' => 'Picked up',
            'authorization' => 'Bearer leaked-token',
        ])->assertOk();

        $log = SteadfastWebhookLog::query()->sole();
        $this->assertArrayNotHasKey('authorization', $log->payload ?? []);
        $this->assertStringNotContainsString('secret-token', (string) $log->raw_body);
        $this->assertSame(0, SteadfastWebhookLog::query()->whereNotNull('error')->where('error', 'like', '%secret-token%')->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), 'secret-token');
    }
}
