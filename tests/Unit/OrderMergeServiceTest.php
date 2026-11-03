<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\Admin\OrderMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OrderMergeService
    {
        return app(OrderMergeService::class);
    }

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Nila',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'area' => 'Mirpur',
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

    public function test_merges_draft_items_into_new_and_discards_the_draft(): void
    {
        $product = $this->product();
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_MERGE',
            'last_inbound_at' => now(),
        ]);

        $target = $this->order(['order_number' => '6001']);
        $target->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1200,
            'purchase_price' => 500,
            'base_price' => 1200,
            'line_total' => 1200,
            'commission_rate' => 0,
            'commission_earned' => 0,
        ]);

        $draft = $this->order([
            'order_number' => '6002',
            'status' => Order::STATUS_DRAFT,
            'channel_conversation_id' => $conversation->id,
            'placed_via' => Order::PLACED_VIA_MESSENGER,
        ]);
        $draft->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'price' => 1200,
            'purchase_price' => 500,
            'base_price' => 1200,
            'line_total' => 2400,
            'commission_rate' => 0,
            'commission_earned' => 0,
        ]);
        $conversation->update(['draft_order_id' => $draft->id]);

        $merged = $this->service()->mergeDraftIntoNew($draft, $target);

        $this->assertSame($target->id, $merged->id);
        $this->assertSame(3, (int) $merged->items->firstWhere('product_id', $product->id)?->quantity);
        $this->assertSame($conversation->id, $merged->channel_conversation_id);
        $this->assertNull($draft->fresh());
        $this->assertNull($conversation->fresh()?->draft_order_id);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertStringContainsString('Merged AI draft #6002', (string) $merged->admin_note);
    }

    public function test_appends_a_new_line_when_the_product_is_not_already_on_the_new_order(): void
    {
        $existing = $this->product(['name' => 'Kurti', 'slug' => 'kurti-'.uniqid()]);
        $added = $this->product(['name' => 'Dupatta', 'slug' => 'dupatta-'.uniqid(), 'price' => 400, 'purchase_price' => 150]);

        $target = $this->order(['order_number' => '6101']);
        $target->items()->create([
            'product_id' => $existing->id,
            'name' => $existing->name,
            'quantity' => 1,
            'price' => 1200,
            'line_total' => 1200,
        ]);

        $draft = $this->order([
            'order_number' => '6102',
            'status' => Order::STATUS_DRAFT,
        ]);
        $draft->items()->create([
            'product_id' => $added->id,
            'name' => $added->name,
            'quantity' => 1,
            'price' => 400,
            'purchase_price' => 150,
            'line_total' => 400,
        ]);

        $merged = $this->service()->mergeDraftIntoNew($draft, $target);

        $this->assertCount(2, $merged->items);
        $this->assertSame(1, (int) $merged->items->firstWhere('product_id', $added->id)?->quantity);
        $this->assertSame(9, $added->fresh()->stock_quantity);
    }

    public function test_merges_create_form_lines_into_an_existing_new_order(): void
    {
        $product = $this->product();
        $target = $this->order(['order_number' => '6201']);

        $merged = $this->service()->mergeLinesIntoNew($target, [[
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1200,
            'purchase_price' => 500,
            'line_total' => 1200,
            'product_image' => null,
        ]]);

        $this->assertSame(1, (int) $merged->items->firstWhere('product_id', $product->id)?->quantity);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertStringContainsString('Merged create-order items', (string) $merged->admin_note);
    }

    public function test_rejects_merging_a_non_draft_or_non_new_target(): void
    {
        $new = $this->order(['order_number' => '6301']);
        $otherNew = $this->order(['order_number' => '6302']);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->mergeDraftIntoNew($new, $otherNew);
    }

    public function test_rejects_merging_into_a_dispatched_order(): void
    {
        $draft = $this->order([
            'order_number' => '6401',
            'status' => Order::STATUS_DRAFT,
        ]);
        $dispatched = $this->order([
            'order_number' => '6402',
            'status' => 'dispatched',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->mergeDraftIntoNew($draft, $dispatched);
    }
}
