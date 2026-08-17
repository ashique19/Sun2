<?php

namespace Tests\Feature;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PathaoWebhookPartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pathao.webhook.enabled' => true,
            'pathao.webhook.secret' => 'pathao-secret',
        ]);
    }

    private function pathao(): Courier
    {
        return Courier::query()->firstOrCreate(
            ['slug' => 'pathao'],
            [
                'name' => 'Pathao',
                'charge' => 70,
                'osd_charge' => 110,
                'cod_percentage' => 1,
                'is_active' => true,
                'is_default' => false,
            ],
        );
    }

    private function dispatchedOrder(): Order
    {
        $courier = $this->pathao();

        return Order::query()->create([
            'order_number' => 'PTH-1001',
            'name' => 'Pathao Customer',
            'phone' => '01710000050',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'due_amount' => 1080,
            'cod_amount' => 1080,
            'collected_amount' => 0,
            'courier_id' => $courier->id,
            'courier_consignment_id' => 'CONS-PTH-1',
            'dispatch_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): void
    {
        $this->postJson('/api/webhooks/pathao', $payload, [
            'X-PATHAO-Signature' => 'pathao-secret',
        ])->assertOk();
    }

    #[Test]
    public function pathao_partial_delivery_does_not_auto_complete_and_creates_attention(): void
    {
        $order = $this->dispatchedOrder();

        $this->postWebhook([
            'event' => 'order.partial-delivery',
            'merchant_order_id' => $order->order_number,
            'consignment_id' => $order->courier_consignment_id,
            'collected_amount' => 700,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->postWebhook([
            'event' => 'order.partial-delivery',
            'merchant_order_id' => $order->order_number,
            'consignment_id' => $order->courier_consignment_id,
            'collected_amount' => 700,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $order->refresh();
        $this->assertSame('dispatched', $order->status);
        $this->assertEquals(0.0, (float) $order->collected_amount);
        $this->assertSame(1, AdminAttentionItem::query()->count());

        $item = AdminAttentionItem::query()->sole();
        $this->assertSame(AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH, $item->issue_type);
        $this->assertTrue((bool) ($item->data['is_partial_delivery'] ?? false));
        $this->assertSame('pathao_webhook', $item->data['source'] ?? null);
        $this->assertStringContainsString('Partial delivery', (string) $item->title);
    }

    #[Test]
    public function pathao_partial_delivered_alias_also_holds_for_review(): void
    {
        $order = $this->dispatchedOrder();
        $order->update(['order_number' => 'PTH-ALIAS']);

        $this->postWebhook([
            'event' => 'order.partial_delivered',
            'merchant_order_id' => 'PTH-ALIAS',
            'consignment_id' => $order->courier_consignment_id,
        ]);

        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertTrue((bool) AdminAttentionItem::query()->value('data')['is_partial_delivery']);
    }

    #[Test]
    public function pathao_full_delivery_with_matching_cod_still_completes(): void
    {
        $order = $this->dispatchedOrder();

        $this->postWebhook([
            'event' => 'order.delivered',
            'merchant_order_id' => $order->order_number,
            'consignment_id' => $order->courier_consignment_id,
            'collected_amount' => 1080,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertEquals(1080.0, (float) $order->collected_amount);
        $this->assertSame(0, AdminAttentionItem::query()->count());
    }

    #[Test]
    public function pathao_full_delivery_with_cod_mismatch_stays_dispatched(): void
    {
        $order = $this->dispatchedOrder();

        $this->postWebhook([
            'event' => 'order.delivered',
            'merchant_order_id' => $order->order_number,
            'consignment_id' => $order->courier_consignment_id,
            'collected_amount' => 500,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $order->refresh();
        $this->assertSame('dispatched', $order->status);
        $this->assertEquals(0.0, (float) $order->collected_amount);
        $this->assertSame(1, AdminAttentionItem::query()->count());
        $this->assertFalse((bool) (AdminAttentionItem::query()->value('data')['is_partial_delivery'] ?? false));
    }
}
