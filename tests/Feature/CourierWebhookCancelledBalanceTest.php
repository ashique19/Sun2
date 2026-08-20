<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CourierWebhookCancelledBalanceTest extends TestCase
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
                'balance' => 0,
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }

    private function dispatchedOrder(Courier $courier, int $bookCredit = 1080, float $courierCharge = 60): Order
    {
        $courier->update(['balance' => $bookCredit]);

        $order = Order::query()->create([
            'order_number' => 'CAN-'.uniqid(),
            'name' => 'Cancel Customer',
            'phone' => '01710000040',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => $courierCharge,
            'cod_amount' => $bookCredit,
            'due_amount' => $bookCredit,
            'total' => $bookCredit,
            'collected_amount' => 0,
            'has_return' => false,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFCAN1',
            'dispatch_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Saree',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'dispatch',
            'amount' => $bookCredit,
            'balance_after' => $bookCredit,
            'order_id' => $order->id,
            'note' => 'Dispatch #'.$order->order_number,
        ]);

        return $order->fresh(['items', 'courier']);
    }

    #[Test]
    public function cancelled_webhook_reverses_cod_credits_fee_and_sets_hr(): void
    {
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, bookCredit: 1080, courierCharge: 60);

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'cancelled',
            'collected_amount' => 0,
            'delivery_fee' => 60,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Consignment cancelled',
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $order->refresh()->load('items');
        $courier->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertSame(1, (int) $order->items->first()->returned_quantity);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(60.0, (float) $order->courier_charge);
        $this->assertSame(0.0, $order->codCharge());
        $this->assertSame(-60.0, $order->courierReceivable());

        // Book: +1080 dispatch, −1080 reverse, −60 fee = −60
        $this->assertSame(-60.0, (float) $courier->balance);
        $this->assertTrue(
            CourierBalanceEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'return')
                ->exists()
        );
        $this->assertTrue(
            CourierBalanceEntry::query()
                ->where('order_id', $order->id)
                ->where('type', 'fee')
                ->where('amount', -60)
                ->exists()
        );

        $summary = app(CourierBalanceService::class)->summarize($courier->fresh());
        $this->assertSame(0.0, $summary['pending']);
        // Receivable includes −60 cancel fee
        $this->assertSame(-60.0, $summary['receivable']);
        // expected_api matches book (−60 after dispatch reverse + cancel fee)
        $this->assertSame(-60.0, $summary['expected_api']);
    }

    #[Test]
    public function returned_webhook_also_reverses_book_and_debits_fee(): void
    {
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, bookCredit: 900, courierCharge: 60);
        $order->update(['order_number' => 'RET-BAL', 'courier_tracker' => 'SFRETBAL']);

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'returned',
            'collected_amount' => 0,
            'delivery_fee' => 60,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel returned',
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $order->refresh();
        $courier->refresh();

        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->has_return);
        $this->assertSame(-60.0, (float) $courier->balance);
        $this->assertSame(0.0, $order->codCharge());
    }

    #[Test]
    public function cancelled_webhook_is_idempotent_on_book_entries(): void
    {
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier);
        $payload = [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'cancelled',
            'collected_amount' => 0,
            'delivery_fee' => 60,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Consignment cancelled',
        ];

        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();
        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $this->assertSame(1, CourierBalanceEntry::query()->where('order_id', $order->id)->where('type', 'return')->count());
        $this->assertSame(1, CourierBalanceEntry::query()->where('order_id', $order->id)->where('type', 'fee')->count());
        $this->assertSame(-60.0, (float) $courier->fresh()->balance);
    }
}
