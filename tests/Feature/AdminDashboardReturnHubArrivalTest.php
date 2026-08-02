<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardReturnHubArrivalTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function returnAtHubOrder(): Order
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

        $product = Product::query()->create([
            'name' => 'Hub Return Saree',
            'slug' => 'hub-return-saree-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'HUB-'.uniqid(),
            'name' => 'Hub Return Customer',
            'phone' => '01710000003',
            'address' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 1000,
            'total' => 1000,
            'has_return' => true,
            'courier_id' => Courier::query()->where('slug', 'steadfast')->value('id'),
            'courier_tracker' => 'SFHUB1',
            'placed_at' => now()->subDays(3),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'returned_quantity' => 1,
            'to_be_returned' => true,
            'return_received' => false,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $order->courier_id,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Consignment has been received at RAMPURA.',
                'updated_at' => now()->subHour()->toDateTimeString(),
            ],
            'created_at' => now()->subHour(),
        ]);

        return $order->fresh(['items']);
    }

    #[Test]
    public function dashboard_lists_return_parcels_at_rampura_hub_and_marks_received(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->returnAtHubOrder();
        $productId = (int) $order->items->first()->product_id;
        $stockBefore = (int) Product::query()->whereKey($productId)->value('stock_quantity');

        Livewire::test(AdminDashboard::class)
            ->assertSee('Return parcels at Steadfast hub')
            ->assertSee('arrived at Steadfast Rampura hub')
            ->assertSee($order->name)
            ->assertSee('#'.$order->order_number)
            ->assertSee('Mark as received')
            ->call('markReturnHubReceived', $order->id)
            ->assertSee('Return marked received for order #'.$order->order_number)
            ->assertDontSee($order->name);

        $item = $order->items()->first();
        $this->assertTrue((bool) $item->return_received);
        $this->assertFalse((bool) $order->fresh()->has_return);
        $this->assertSame($stockBefore + 1, (int) Product::query()->whereKey($productId)->value('stock_quantity'));
    }

    #[Test]
    public function dashboard_lists_exchange_hr_orders_at_hub_and_clears_hr_on_receive(): void
    {
        $this->actingAs($this->adminUser());

        $order = Order::query()->create([
            'order_number' => 'HUB-EX',
            'name' => 'Exchange Hub Customer',
            'phone' => '01710000005',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'dispatched',
            'subtotal' => 700,
            'total' => 700,
            'has_return' => true,
            'is_replacement' => true,
            'placed_at' => now()->subDays(2),
            'dispatch_date' => now()->subDay(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Exchange Kurti',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 700,
            'purchase_price' => 250,
            'line_total' => 700,
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => null,
            'api_data' => [
                'notification_type' => 'tracking_update',
                'tracking_message' => 'Consignment has been received at RAMPURA.',
                'updated_at' => now()->subMinutes(20)->toDateTimeString(),
            ],
            'created_at' => now()->subMinutes(20),
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertSee('Return parcels at Steadfast hub')
            ->assertSeeHtml('wire:key="return-hub-arrival-'.$order->id.'"')
            ->assertSee('Exchange')
            ->call('markReturnHubReceived', $order->id)
            ->assertSee('Return marked received for order #'.$order->order_number)
            ->assertDontSeeHtml('wire:key="return-hub-arrival-'.$order->id.'"');

        $this->assertFalse((bool) $order->fresh()->has_return);
        $this->assertNotNull($order->fresh()->return_hub_arrived_at);
    }

    #[Test]
    public function steadfast_webhook_stamps_hub_arrival_for_return_pending_orders(): void
    {
        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);

        $order = Order::query()->create([
            'order_number' => 'HUB-WH',
            'name' => 'Webhook Return',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 500,
            'total' => 500,
            'has_return' => true,
            'courier_tracker' => 'SFWH1',
            'placed_at' => now()->subDay(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Returned Item',
            'quantity' => 1,
            'returned_quantity' => 1,
            'to_be_returned' => true,
            'return_received' => false,
            'price' => 500,
            'purchase_price' => 200,
            'line_total' => 500,
        ]);

        $this->postJson('/api/steadfast/webhook', [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'tracking_message' => 'Consignment has been received at RAMPURA.',
            'updated_at' => now()->toDateTimeString(),
        ], [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $this->assertNotNull($order->fresh()->return_hub_arrived_at);
    }
}
