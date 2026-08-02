<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersHasReturnDeliveredTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function deliveredOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'DL-'.uniqid(),
            'name' => 'Delivered Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 980,
            'total' => 980,
            'has_return' => false,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);
    }

    #[Test]
    public function delivered_segment_shows_hr_and_flags_has_return(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->deliveredOrder();

        Livewire::test(AdminOrders::class, ['segment' => 'delivered'])
            ->assertSeeHtml('wire:click="toggleHasReturn('.$order->id.')"')
            ->assertSee('H/R')
            ->call('toggleHasReturn', $order->id)
            ->assertDontSeeHtml('wire:click="toggleHasReturn('.$order->id.')"');

        $order->refresh();
        $this->assertTrue($order->has_return);
        $this->assertSame('delivered', $order->status);

        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'return-pending')
                ->whereKey($order->id)
                ->exists()
        );
    }
}
