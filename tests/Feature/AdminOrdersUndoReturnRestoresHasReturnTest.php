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

class AdminOrdersUndoReturnRestoresHasReturnTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function undo_received_restores_has_return_and_return_pending(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Undo Saree',
            'slug' => 'undo-saree-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'UR-'.uniqid(),
            'name' => 'Undo Customer',
            'phone' => '01710000055',
            'address' => 'Dhaka',
            'status' => 'cancelled',
            'subtotal' => 1000,
            'total' => 1000,
            'has_return' => true,
            'placed_at' => now()->subDay(),
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

        $component = Livewire::test(AdminOrders::class, ['segment' => 'return-pending'])
            ->call('markReturnReceived', $order->id);

        $order->refresh();
        $this->assertFalse((bool) $order->has_return);
        $this->assertTrue((bool) $order->items()->first()->return_received);

        $component->call('undoReturnReceived', $order->id);

        $order->refresh();
        $item = $order->items()->first();

        $this->assertFalse((bool) $item->return_received);
        $this->assertTrue((bool) $order->has_return);
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }
}
