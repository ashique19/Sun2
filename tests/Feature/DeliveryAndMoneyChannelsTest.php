<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentMethod;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderDispatchService;
use App\Services\Orders\OrderPaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeliveryAndMoneyChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentMethod::query()->firstOrCreate(
            ['code' => 'cod'],
            ['name' => 'COD', 'is_active' => true],
        );
        PaymentMethod::query()->firstOrCreate(
            ['code' => 'bkash'],
            ['name' => 'bKash', 'is_active' => true],
        );

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(?Courier $courier, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'CH-'.uniqid(),
            'name' => 'Channel Customer',
            'phone' => '01710004444',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 3000,
            'delivery_charge' => 130,
            'courier_charge' => 80,
            'packaging_cost' => 20,
            'total' => 3130,
            'cod_amount' => 3130,
            'due_amount' => 3130,
            'paid_amount' => 0,
            'collected_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier?->id,
            'placed_at' => now(),
        ], $overrides));

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 3000,
            'purchase_price' => 1450,
            'unit_cost' => 1450,
            'line_total' => 3000,
        ]);

        return $order->fresh(['items', 'courier', 'paymentTransactions']);
    }

    #[Test]
    public function prepaid_bkash_does_not_change_steadfast_assignment_or_their_fee(): void
    {
        $courier = $this->steadfast();
        $order = $this->order($courier);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 3130,
            kind: 'advance',
        );

        $order = $order->fresh(['items', 'courier', 'paymentTransactions']);
        $money = $order->moneyTotals();

        $this->assertSame($courier->id, $order->courier_id);
        $this->assertSame(80.0, (float) $order->courier_charge);
        $this->assertSame('bkash', $order->payment_method);
        $this->assertSame(3130.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(0.0, $order->collectableAmount());
        $this->assertSame(0.0, $money->remittanceBase);
        $this->assertSame(0.0, $money->codCharge);
        $this->assertSame(3130.0, $money->inHouseReceived);
        $this->assertSame(-80.0, $money->courierReceivable);
        $this->assertSame(1580.0, $money->grossProfit); // 3130 − 80 − 1450 − 20
    }

    #[Test]
    public function unpaid_without_courier_is_not_courier_remittance(): void
    {
        $order = $this->order(null);
        $money = $order->moneyTotals();

        $this->assertSame(3130.0, $order->collectableAmount());
        $this->assertSame(0.0, $money->remittanceBase);
        $this->assertSame(0.0, $money->codCharge);
        $this->assertSame(0.0, $money->inHouseReceived);
        $this->assertSame(-80.0, $money->courierReceivable);
    }

    #[Test]
    public function leftover_after_partial_bkash_is_the_only_courier_money(): void
    {
        $courier = $this->steadfast();
        $order = $this->order($courier);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 1000,
            kind: 'advance',
        );

        $order = $order->fresh(['items', 'courier', 'paymentTransactions']);
        $money = $order->moneyTotals();

        $this->assertSame(2130.0, $order->collectableAmount());
        $this->assertSame(1000.0, $money->inHouseReceived);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(2130.0, $money->remittanceBase);
        // Steadfast: (2130 − 80) × 1% = 20.5
        $this->assertSame(20.5, $money->codCharge);
        $this->assertSame(2029.5, $money->courierReceivable);
        $this->assertSame($courier->id, $order->courier_id);
    }

    #[Test]
    public function prepaid_dispatch_sends_zero_cod_and_keeps_the_courier(): void
    {
        $courier = $this->steadfast();
        $order = $this->order($courier);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 3130,
            kind: 'advance',
        );

        Http::fake([
            'portal.packzy.com/api/v1/create_order' => Http::response([
                'status' => 200,
                'consignment' => [
                    'consignment_id' => 555,
                    'invoice' => $order->order_number,
                    'tracking_code' => 'PREPAID1',
                    'cod_amount' => 0,
                ],
            ], 200),
        ]);

        $dispatched = app(OrderDispatchService::class)->dispatchToSteadfast($order->fresh());

        $this->assertSame('dispatched', $dispatched->status);
        $this->assertSame($courier->id, $dispatched->courier_id);
        $this->assertSame(0.0, $dispatched->collectableAmount());
        $this->assertSame(3130.0, $dispatched->moneyTotals()->inHouseReceived);
        $this->assertSame(0.0, $dispatched->codCharge());

        Http::assertSent(fn ($request) => $request->url() === 'https://portal.packzy.com/api/v1/create_order'
            && (int) $request['cod_amount'] === 0);
    }

    #[Test]
    public function analytics_counts_prepaid_cash_not_only_courier_collection(): void
    {
        $courier = $this->steadfast();
        $order = $this->order($courier, [
            'status' => 'delivered',
            'actual_delivery_date' => now(),
            'created_at' => '2026-07-15 10:00:00',
            'placed_at' => '2026-07-15 10:00:00',
        ]);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 3130,
            kind: 'advance',
        );

        $order = $order->fresh(['items', 'courier', 'paymentTransactions']);
        $econ = app(AnalyticsService::class)->orderContribution($order);

        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(3130.0, $econ['revenue']);
        $this->assertSame(0.0, $econ['cod']);
        $this->assertSame(1550.0, $econ['direct']); // 1450 + 20 + 80 + 0
        $this->assertSame(1580.0, $econ['profit']);
    }
}
