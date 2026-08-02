<?php

namespace Tests\Unit;

use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Admin\ReturnHubArrivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnHubArrivalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReturnHubArrivalService
    {
        return app(ReturnHubArrivalService::class);
    }

    private function returnPendingOrder(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'RH-'.uniqid(),
            'name' => 'Return Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 1000,
            'total' => 1000,
            'has_return' => true,
            'placed_at' => now()->subDay(),
        ], $overrides));

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Sample Saree',
            'quantity' => 1,
            'returned_quantity' => 1,
            'to_be_returned' => true,
            'return_received' => false,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        return $order->fresh(['items']);
    }

    #[Test]
    public function detects_rampura_hub_arrival_message(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isHubArrivalMessage('Consignment has been received at RAMPURA.'));
        $this->assertTrue($service->isHubArrivalMessage('consignment has been received at rampura hub'));
        $this->assertFalse($service->isHubArrivalMessage('Consignment is in transit to RAMPURA.'));
        $this->assertFalse($service->isHubArrivalMessage('Delivered successfully'));
    }

    #[Test]
    public function observe_message_stamps_only_return_pending_orders(): void
    {
        $pending = $this->returnPendingOrder();
        $plain = $this->returnPendingOrder([
            'order_number' => 'RH-PLAIN',
            'has_return' => false,
            'status' => 'delivered',
        ]);

        $this->assertTrue($this->service()->observeMessage(
            $pending,
            'Consignment has been received at RAMPURA.',
            '2026-08-01 10:00:00',
        ));
        $this->assertFalse($this->service()->observeMessage(
            $plain->fresh(),
            'Consignment has been received at RAMPURA.',
        ));

        $this->assertNotNull($pending->fresh()->return_hub_arrived_at);
        $this->assertNull($plain->fresh()->return_hub_arrived_at);
    }

    #[Test]
    public function sync_from_courier_logs_backfills_existing_webhook_data(): void
    {
        $order = $this->returnPendingOrder();

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => null,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Consignment has been received at RAMPURA.',
                'updated_at' => '2026-08-01 12:30:00',
            ],
            'created_at' => now(),
        ]);

        $this->assertTrue($this->service()->syncFromCourierLogs($order->fresh()));
        $this->assertNotNull($order->fresh()->return_hub_arrived_at);

        $awaiting = $this->service()->ordersAwaitingReceive();
        $this->assertTrue($awaiting->contains('id', $order->id));
    }
}
