<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\OrderPackagingCourierConfirmService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPackagingCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderPackagingCourierConfirmTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function dispatchedOrder(): Order
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast-confirm-txn',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'PC-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000000',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'courier_id' => $courier->id,
            'courier_charge' => 60,
            'packaging_cost' => 0,
            'dispatch_date' => now(),
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        return $order;
    }

    #[Test]
    public function confirm_applies_packaging_and_courier_together(): void
    {
        $order = $this->dispatchedOrder();
        $actor = User::factory()->create();

        app(OrderPackagingCourierConfirmService::class)->confirm(
            order: $order,
            packagingAmount: 21,
            courierAmount: 75,
            actor: $actor,
        );

        $order->refresh();
        $this->assertSame(21.0, (float) $order->packaging_cost);
        $this->assertSame(75.0, (float) $order->courier_charge);
        $this->assertNotNull($order->courier_charge_confirmed_at);
        $this->assertSame($actor->id, (int) $order->courier_charge_confirmed_by);
    }

    #[Test]
    public function packaging_rolls_back_when_courier_confirm_fails(): void
    {
        $order = $this->dispatchedOrder();

        $this->mock(OrderCourierChargeSync::class, function ($mock): void {
            $mock->shouldReceive('confirm')
                ->once()
                ->andThrow(new \RuntimeException('courier confirm failed'));
        });

        $service = new OrderPackagingCourierConfirmService(
            app(OrderPackagingCost::class),
            app(OrderCourierChargeSync::class),
        );

        try {
            $service->confirm($order, 21, 75, null);
            $this->fail('Expected confirm to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('courier confirm failed', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(0.0, (float) $order->packaging_cost);
        $this->assertNull($order->courier_charge_confirmed_at);
        $this->assertSame(60.0, (float) $order->courier_charge);
    }
}
