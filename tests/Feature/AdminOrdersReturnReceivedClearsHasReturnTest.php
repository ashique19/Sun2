<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersReturnReceivedClearsHasReturnTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function cancelledHasReturnOrder(): Order
    {
        $product = Product::query()->create([
            'name' => 'Return Saree',
            'slug' => 'return-saree-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 3,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'CR-'.uniqid(),
            'name' => 'Cancelled Return Customer',
            'phone' => '01710000044',
            'address' => 'Dhaka',
            'status' => 'cancelled',
            'subtotal' => 1000,
            'total' => 1000,
            'has_return' => true,
            'placed_at' => now()->subDays(5),
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

        return $order->fresh(['items']);
    }

    #[Test]
    public function return_pending_received_restores_stock_clears_hr_and_leaves_segment(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->cancelledHasReturnOrder();
        $productId = (int) $order->items->first()->product_id;
        $stockBefore = (int) Product::query()->whereKey($productId)->value('stock_quantity');

        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );

        Livewire::test(AdminOrders::class, ['segment' => 'return-pending'])
            ->assertSee($order->name)
            ->assertSeeHtml('wire:click="markReturnReceived('.$order->id.')"')
            ->call('markReturnReceived', $order->id)
            ->assertDontSee($order->name);

        $order->refresh();
        $item = $order->items()->first();

        $this->assertTrue((bool) $item->return_received);
        $this->assertFalse((bool) $order->has_return);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame($stockBefore + 1, (int) Product::query()->whereKey($productId)->value('stock_quantity'));
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }

    #[Test]
    public function return_pending_includes_exchange_without_qty_and_received_skips_the_list(): void
    {
        $this->actingAs($this->adminUser());

        $original = Order::query()->create([
            'order_number' => 'EX-ORIG-'.uniqid(),
            'name' => 'Exchange Return Customer',
            'phone' => '01710000046',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'total' => 1000,
            'has_return' => true,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $original->id,
            'name' => 'Exchange Dress',
            'quantity' => 1,
            'returned_quantity' => 0,
            'to_be_returned' => false,
            'return_received' => false,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($original->id)
                ->exists()
        );

        Livewire::test(AdminOrders::class, ['segment' => 'return-pending'])
            ->assertSee($original->name)
            ->assertSeeHtml('wire:click="markReturnReceived('.$original->id.')"')
            ->call('markReturnReceived', $original->id)
            ->assertDontSee($original->name);

        $this->assertFalse((bool) $original->fresh()->has_return);
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($original->id)
                ->exists()
        );
    }

    #[Test]
    public function return_pending_includes_hr_only_inbound_and_received_skips_the_list(): void
    {
        $this->actingAs($this->adminUser());

        $order = Order::query()->create([
            'order_number' => 'HR-'.uniqid(),
            'name' => 'H/R Only Customer',
            'phone' => '01710000047',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 800,
            'total' => 800,
            'has_return' => true,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'H/R Kurti',
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 800,
            'purchase_price' => 250,
            'line_total' => 800,
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'return-pending'])
            ->assertSee($order->name)
            ->call('markReturnReceived', $order->id)
            ->assertDontSee($order->name);

        $this->assertFalse((bool) $order->fresh()->has_return);
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }
}
