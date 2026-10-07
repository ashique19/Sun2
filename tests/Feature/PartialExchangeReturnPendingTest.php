<?php

namespace Tests\Feature;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\ReturnHubArrivalService;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartialExchangeReturnPendingTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dispatchedOrder(array $overrides = []): Order
    {
        $this->steadfast();

        return Order::query()->create(array_merge([
            'order_number' => 'PRP-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000901',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 2000,
            'delivery_charge' => 80,
            'total' => 2080,
            'due_amount' => 2080,
            'cod_amount' => 2080,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'courier_tracker' => 'SFR_PRP_'.uniqid(),
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
        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);

        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();
    }

    #[Test]
    public function partial_webhook_alone_does_not_flag_return_pending(): void
    {
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-PARTIAL-ATTN',
            'courier_tracker' => 'SFR_PARTIAL_ATTN',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item A',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item B',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'collected_amount' => 1080,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);

        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertFalse((bool) $order->fresh()->has_return);
        $this->assertTrue(
            AdminAttentionItem::query()
                ->where('order_id', $order->id)
                ->get()
                ->contains(fn (AdminAttentionItem $item) => (bool) ($item->data['is_partial_delivery'] ?? false))
        );
    }

    #[Test]
    public function submitting_returned_quantities_flags_return_pending(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-PARTIAL-QTY',
            'courier_tracker' => 'SFR_PARTIAL_QTY',
        ]);
        $kept = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item A',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);
        $returned = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item B',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'collected_amount' => 1080,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);
        $this->assertFalse((bool) $order->fresh()->has_return);

        app(OrderDeliveryReturnService::class)->partialReturn(
            $order->fresh(),
            [(int) $kept->id => 0, (int) $returned->id => 1],
            1080.0,
        );

        $order->refresh()->load('items');
        $this->assertSame('delivered', $order->status);
        $this->assertTrue((bool) $order->has_return);
        $this->assertSame(0, (int) $order->items->firstWhere('id', $kept->id)->returned_quantity);
        $this->assertSame(1, (int) $order->items->firstWhere('id', $returned->id)->returned_quantity);
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }

    #[Test]
    public function mark_delivered_after_partial_webhook_without_qty_does_not_flag(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-PARTIAL-DELIVER',
            'courier_tracker' => 'SFR_PARTIAL_DELIVER',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 2000,
            'purchase_price' => 800,
            'line_total' => 2000,
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'collected_amount' => 1080,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);

        app(OrderDeliveryReturnService::class)->markDelivered($order->fresh(), collectedAmount: 1080.0);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertFalse((bool) $order->has_return);
    }

    #[Test]
    public function full_deliver_without_partial_signal_does_not_flag_return_pending(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-FULL',
            'courier_tracker' => 'SFR_FULL_RP',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 2000,
            'purchase_price' => 800,
            'line_total' => 2000,
        ]);

        app(OrderDeliveryReturnService::class)->markDelivered($order->fresh());

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertFalse((bool) $order->has_return);
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }

    #[Test]
    public function cod_mismatch_attention_without_partial_does_not_flag_on_deliver(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-MISMATCH',
            'courier_tracker' => 'SFR_MISMATCH_RP',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 2000,
            'purchase_price' => 800,
            'line_total' => 2000,
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'delivered',
            'cod_amount' => 1500,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered successfully',
        ]);

        $this->assertSame('dispatched', $order->fresh()->status);
        $item = AdminAttentionItem::query()->where('order_id', $order->id)->sole();
        $this->assertFalse((bool) ($item->data['is_partial_delivery'] ?? false));

        app(OrderDeliveryReturnService::class)->markDelivered($order->fresh(), collectedAmount: 2080.0);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertFalse((bool) $order->fresh()->has_return);
    }

    #[Test]
    public function steadfast_webhook_delivering_exchange_flags_original_return_pending(): void
    {
        $original = Order::query()->create([
            'order_number' => 'PRP-ORIG',
            'name' => 'Exchange Customer',
            'phone' => '01710000902',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
            'collected_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'has_return' => false,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Dress',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $exchange = $this->dispatchedOrder([
            'order_number' => 'PRP-EXC',
            'courier_tracker' => 'SFR_EXC_RP',
            'name' => 'Exchange Customer',
            'phone' => '01710000902',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'subtotal' => 0,
            'delivery_charge' => 0,
            'total' => 0,
            'due_amount' => 0,
            'cod_amount' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
        ]);
        OrderProduct::query()->create([
            'order_id' => $exchange->id,
            'name' => 'Dress',
            'quantity' => 1,
            'price' => 0,
            'purchase_price' => 400,
            'line_total' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(),
            $exchange->fresh(),
        );
        $this->assertTrue((bool) $original->fresh()->has_return);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $exchange->order_number,
            'tracking_id' => $exchange->courier_tracker,
            'status' => 'delivered',
            'cod_amount' => 0,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Delivered successfully',
        ]);

        $this->assertSame('delivered', $exchange->fresh()->status);
        $this->assertTrue((bool) $original->fresh()->has_return);
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($original->id)
                ->exists()
        );
    }

    #[Test]
    public function returned_qty_then_rampura_hub_appears_on_return_arrival_list(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-HUB-PARTIAL',
            'courier_tracker' => 'SFR_HUB_PARTIAL',
        ]);
        $kept = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Kept',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);
        $returned = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Returned',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'collected_amount' => 1080,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);
        $this->assertFalse((bool) $order->fresh()->has_return);

        app(OrderDeliveryReturnService::class)->partialReturn(
            $order->fresh(),
            [(int) $kept->id => 0, (int) $returned->id => 1],
            1080.0,
        );
        $this->assertTrue((bool) $order->fresh()->has_return);

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'tracking_message' => 'Consignment has been received at RAMPURA.',
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertNotNull($order->fresh()->return_hub_arrived_at);
        $awaiting = app(ReturnHubArrivalService::class)->ordersAwaitingReceive();
        $this->assertTrue($awaiting->contains('id', $order->id));
    }

    #[Test]
    public function exchange_link_then_rampura_on_original_appears_on_return_arrival_list(): void
    {
        $original = Order::query()->create([
            'order_number' => 'PRP-HUB-ORIG',
            'name' => 'Exchange Customer',
            'phone' => '01710000903',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
            'collected_amount' => 1000,
            'has_return' => false,
            'courier_tracker' => 'SFR_HUB_ORIG',
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Dress',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $exchange = $this->dispatchedOrder([
            'order_number' => 'PRP-HUB-EXC',
            'courier_tracker' => 'SFR_HUB_EXC',
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'subtotal' => 0,
            'total' => 0,
            'due_amount' => 0,
            'cod_amount' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(),
            $exchange->fresh(),
        );
        $this->assertTrue((bool) $original->fresh()->has_return);

        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $original->order_number,
            'tracking_id' => $original->courier_tracker,
            'tracking_message' => 'Consignment has been received at RAMPURA.',
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertNotNull($original->fresh()->return_hub_arrived_at);
        $awaiting = app(ReturnHubArrivalService::class)->ordersAwaitingReceive();
        $this->assertTrue($awaiting->contains('id', $original->id));
    }

    #[Test]
    public function rampura_before_qty_backfills_when_admin_submits_partial_return(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'order_number' => 'PRP-HUB-RACE',
            'courier_tracker' => 'SFR_HUB_RACE',
        ]);
        $item = OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Item',
            'quantity' => 2,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 2000,
        ]);

        // Hub arrival first — stamped only after admin enters returned qty.
        $this->postWebhook([
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'tracking_message' => 'Consignment has been received at RAMPURA.',
            'updated_at' => now()->subMinute()->toDateTimeString(),
        ]);
        $this->assertNull($order->fresh()->return_hub_arrived_at);
        $this->assertFalse((bool) $order->fresh()->has_return);

        $this->postWebhook([
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'collected_amount' => 1000,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ]);
        $this->assertFalse((bool) $order->fresh()->has_return);
        $this->assertNull($order->fresh()->return_hub_arrived_at);

        app(OrderDeliveryReturnService::class)->partialReturn(
            $order->fresh(),
            [(int) $item->id => 1],
            1000.0,
        );

        $order->refresh();
        $this->assertTrue((bool) $order->has_return);
        $this->assertNotNull($order->return_hub_arrived_at);
        $awaiting = app(ReturnHubArrivalService::class)->ordersAwaitingReceive();
        $this->assertTrue($awaiting->contains('id', $order->id));
    }
}
