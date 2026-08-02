<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CourierWebhookReturnedHasReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);
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

    private function dispatchedOrderWithItems(): Order
    {
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'RET-'.uniqid(),
            'name' => 'Return Webhook Customer',
            'phone' => '01710000020',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 2000,
            'total' => 2000,
            'has_return' => false,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFRET1',
            'dispatch_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Saree A',
            'quantity' => 2,
            'returned_quantity' => 0,
            'to_be_returned' => false,
            'return_received' => false,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 2000,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Saree B',
            'quantity' => 1,
            'returned_quantity' => 0,
            'to_be_returned' => false,
            'return_received' => false,
            'price' => 500,
            'purchase_price' => 200,
            'line_total' => 500,
        ]);

        return $order->fresh(['items']);
    }

    #[Test]
    public function steadfast_returned_webhook_sets_hr_and_all_items_return_pending(): void
    {
        $order = $this->dispatchedOrderWithItems();

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'returned',
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel has been returned',
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $order->refresh()->load('items');

        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );

        foreach ($order->items as $item) {
            $this->assertSame((int) $item->quantity, (int) $item->returned_quantity);
            $this->assertTrue((bool) $item->to_be_returned);
            $this->assertFalse((bool) $item->return_received);
        }
    }

    #[Test]
    public function steadfast_cancel_and_return_webhook_also_flags_hr(): void
    {
        $order = $this->dispatchedOrderWithItems();
        $order->update(['order_number' => 'RET-CR', 'courier_tracker' => 'SFRETCR']);

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'cancel and return',
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Cancel and return',
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $order->refresh()->load('items');

        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertSame(2, (int) $order->items->firstWhere('name', 'Saree A')->returned_quantity);
        $this->assertSame(1, (int) $order->items->firstWhere('name', 'Saree B')->returned_quantity);
    }

    #[Test]
    public function redelivery_of_returned_webhook_repairs_missing_hr_on_already_returned_order(): void
    {
        $order = $this->dispatchedOrderWithItems();
        $order->update([
            'order_number' => 'RET-REPAIR',
            'courier_tracker' => 'SFREPAIR',
            'status' => 'returned',
            'has_return' => false,
        ]);

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'returned',
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel has been returned',
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $order->refresh()->load('items');

        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertSame(3, (int) $order->items->sum('returned_quantity'));
    }
}
