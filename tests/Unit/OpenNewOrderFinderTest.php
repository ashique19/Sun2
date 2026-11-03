<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Admin\OpenNewOrderFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenNewOrderFinderTest extends TestCase
{
    use RefreshDatabase;

    private function finder(): OpenNewOrderFinder
    {
        return app(OpenNewOrderFinder::class);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Nila',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 1200,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1280,
            'cod_amount' => 1280,
            'due_amount' => 1280,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => Order::STATUS_NEW,
            'placed_at' => now(),
        ], $overrides));
    }

    public function test_finds_open_new_orders_for_the_same_phone(): void
    {
        $open = $this->order(['order_number' => '5001']);
        $this->order([
            'order_number' => '5002',
            'status' => Order::STATUS_DRAFT,
        ]);
        $this->order([
            'order_number' => '5003',
            'status' => 'dispatched',
        ]);
        $this->order([
            'order_number' => '5004',
            'phone' => '01711111111',
        ]);

        $matches = $this->finder()->forPhone('01627237432');

        $this->assertSame([$open->id], $matches->pluck('id')->all());
    }

    public function test_excludes_the_current_order_id(): void
    {
        $keep = $this->order(['order_number' => '5101']);
        $skip = $this->order(['order_number' => '5102']);

        $matches = $this->finder()->forPhone('01627237432', $skip->id);

        $this->assertSame([$keep->id], $matches->pluck('id')->all());
    }

    public function test_ignores_confirmed_orders_so_only_status_new_is_offered(): void
    {
        $this->order([
            'order_number' => '5201',
            'status' => 'confirmed',
        ]);

        $this->assertTrue($this->finder()->forPhone('01627237432')->isEmpty());
    }

    public function test_returns_empty_for_invalid_phones(): void
    {
        $this->order();

        $this->assertTrue($this->finder()->forPhone('')->isEmpty());
        $this->assertTrue($this->finder()->forPhone('not-a-phone')->isEmpty());
    }

    public function test_summaries_include_item_counts(): void
    {
        $order = $this->order(['order_number' => '5301', 'name' => 'Farhana']);
        $order->items()->create([
            'name' => 'Kurti',
            'quantity' => 2,
            'price' => 600,
            'line_total' => 1200,
        ]);

        $summary = $this->finder()->summarize($order->fresh('items'));

        $this->assertSame($order->id, $summary['id']);
        $this->assertSame('5301', $summary['order_number']);
        $this->assertSame(2, $summary['item_count']);
        $this->assertSame('Farhana', $summary['name']);
    }

    public function test_find_matching_new_rejects_other_phones(): void
    {
        $order = $this->order();

        $this->assertNull($this->finder()->findMatchingNew($order->id, '01711111111'));
        $this->assertSame($order->id, $this->finder()->findMatchingNew($order->id, '01627237432')?->id);
    }
}
