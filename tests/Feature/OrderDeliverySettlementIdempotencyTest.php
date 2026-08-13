<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Orders\OrderPaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderDeliverySettlementIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ORD-SETTLE-1',
            'name' => 'Customer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1200,
            'due_amount' => 1200,
            'cod_amount' => 1200,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'is_replacement' => false,
            'has_return' => false,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function record_collection_reuses_settlement_external_id(): void
    {
        $order = $this->order();
        $settlement = app(OrderDeliverySettlement::class);

        $settlement->recordCollection(
            order: $order,
            amount: 1200,
            meta: ['source' => 'admin_deliver'],
        );
        $settlement->recordCollection(
            order: $order->fresh(),
            amount: 1200,
            meta: ['source' => 'steadfast_webhook'],
        );

        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            OrderDeliverySettlement::settlementExternalId((int) $order->id),
            PaymentTransaction::query()->where('order_id', $order->id)->value('external_id'),
        );
        $this->assertSame(0.0, (float) $order->fresh()->due_amount);
    }

    #[Test]
    public function payment_recorder_returns_existing_row_for_same_method_and_reference(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-SETTLE-2',
            'due_amount' => 500,
            'total' => 500,
            'cod_amount' => 500,
        ]);
        $recorder = app(OrderPaymentRecorder::class);
        $reference = 'cod:manual-test:order:'.$order->id;

        $first = $recorder->record(
            order: $order,
            method: 'cod',
            amount: 500,
            kind: 'settlement',
            reference: $reference,
        );
        $second = $recorder->record(
            order: $order->fresh(),
            method: 'cod',
            amount: 500,
            kind: 'settlement',
            reference: $reference,
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function partial_return_uses_distinct_external_id_from_settlement(): void
    {
        $order = $this->order([
            'order_number' => 'ORD-SETTLE-3',
            'due_amount' => 800,
            'total' => 800,
            'cod_amount' => 800,
        ]);
        $settlement = app(OrderDeliverySettlement::class);

        $settlement->recordCollection(
            order: $order,
            amount: 400,
            meta: ['source' => 'admin_partial_return'],
        );

        $this->assertSame(
            OrderDeliverySettlement::partialReturnExternalId((int) $order->id),
            PaymentTransaction::query()->where('order_id', $order->id)->value('external_id'),
        );
        $this->assertNotSame(
            OrderDeliverySettlement::settlementExternalId((int) $order->id),
            PaymentTransaction::query()->where('order_id', $order->id)->value('external_id'),
        );
    }
}
