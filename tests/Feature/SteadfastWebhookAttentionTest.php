<?php

namespace Tests\Feature;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderDeliverySettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastWebhookAttentionTest extends TestCase
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
            'order_number' => 'ORD-1001',
            'name' => 'Customer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1200,
            'due_amount' => 1200,
            'cod_amount' => 1200,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'courier_tracker' => 'SFR_123',
            'courier_consignment_id' => '277193413',
            'is_replacement' => false,
            'has_return' => false,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): void
    {
        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();
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
    public function delivery_status_mismatch_creates_one_reusable_attention_item(): void
    {
        $order = $this->order();

        $payload = [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'cod_amount' => 700,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ];

        $this->postWebhook($payload);
        $this->postWebhook($payload);

        $this->assertSame(1, AdminAttentionItem::query()->count());
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertStringContainsString('Partial delivery', AdminAttentionItem::query()->value('title'));
    }

    #[Test]
    public function partial_delivered_approval_pending_with_lower_cod_creates_attention(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-APPROVAL',
            'courier_tracker' => 'SFR_APPROVAL',
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'consignment_id' => $order->courier_consignment_id,
            'status' => 'partial_delivered_approval_pending',
            'cod_amount' => 500,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered, awaiting approval',
        ]);

        $item = AdminAttentionItem::query()->sole();

        $this->assertSame(AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH, $item->issue_type);
        $this->assertTrue((bool) ($item->data['is_partial_delivery'] ?? false));
        $this->assertSame(1200.0, (float) $item->data['expected_amount']);
        $this->assertSame(500.0, (float) $item->data['collected_amount']);
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    #[Test]
    public function partial_delivered_with_matching_cod_still_needs_attention(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-PARTIAL-MATCH',
            'courier_tracker' => 'SFR_PARTIAL_MATCH',
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'cod_amount' => 1200,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);

        $this->assertSame(1, AdminAttentionItem::query()->count());
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertNotSame('delivered', $order->fresh()->status);
    }

    #[Test]
    public function delivered_with_matching_cod_marks_delivered(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-FULL',
            'courier_tracker' => 'SFR_FULL',
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'delivered',
            'cod_amount' => 1200,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered successfully',
        ]);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, AdminAttentionItem::query()->count());
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            OrderDeliverySettlement::settlementExternalId((int) $order->id),
            PaymentTransaction::query()->where('order_id', $order->id)->value('external_id'),
        );
    }

    #[Test]
    public function duplicate_delivered_webhook_records_cod_once(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-DUP',
            'courier_tracker' => 'SFR_DUP',
        ]);

        $payload = [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'delivered',
            'cod_amount' => 1200,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered successfully',
        ];

        $this->postWebhook($payload);
        $this->postWebhook($payload);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->where('method', 'cod')->count());
        $this->assertSame(1200.0, (float) PaymentTransaction::query()->where('order_id', $order->id)->value('amount'));
        $this->assertSame(0.0, (float) $order->fresh()->due_amount);
    }

    #[Test]
    public function delivered_with_cod_mismatch_creates_attention_and_blocks_delivery(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-MISMATCH',
            'courier_tracker' => 'SFR_MISMATCH',
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'delivered',
            'cod_amount' => 900,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered',
        ]);

        $item = AdminAttentionItem::query()->sole();

        $this->assertSame(AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH, $item->issue_type);
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertStringContainsString('COD Mismatch', $item->title);
    }

    #[Test]
    public function webhook_finds_order_by_consignment_id_when_invoice_missing(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-CID',
            'courier_tracker' => 'SFR_CID',
            'courier_consignment_id' => '999888777',
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'consignment_id' => '999888777',
            'status' => 'delivered',
            'cod_amount' => 800,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered',
        ]);

        $this->assertSame(1, AdminAttentionItem::query()->count());
        $this->assertSame($order->id, AdminAttentionItem::query()->value('order_id'));
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    #[Test]
    public function tracking_update_delivered_with_cod_mismatch_creates_attention_and_blocks_delivery(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-1002',
            'courier_tracker' => 'SFR_456',
        ]);

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'cod_amount' => 600,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel delivered successfully',
        ]);

        $item = AdminAttentionItem::query()->sole();

        $this->assertSame(AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH, $item->issue_type);
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    #[Test]
    public function tracking_update_with_matching_cod_marks_delivered(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-1003',
            'courier_tracker' => 'SFR_789',
        ]);

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'cod_amount' => 1200,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel delivered successfully',
        ]);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, AdminAttentionItem::query()->count());
    }
}
