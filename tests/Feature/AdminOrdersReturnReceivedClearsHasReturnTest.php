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
}
