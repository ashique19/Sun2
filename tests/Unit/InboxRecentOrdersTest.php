<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Admin\InboxRecentOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxRecentOrdersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recent_returns_non_draft_orders_from_the_last_seven_days(): void
    {
        $conversation = $this->conversation();

        $recent = $this->order($conversation, [
            'order_number' => 'INB-1001',
            'phone' => '01711111111',
            'status' => 'confirmed',
            'placed_at' => now()->subDays(2),
        ]);
        $byPhone = $this->order($conversation, [
            'order_number' => 'INB-1002',
            'phone' => '01711111111',
            'channel_conversation_id' => null,
            'status' => 'new',
            'placed_at' => now()->subDay(),
        ]);
        $this->order($conversation, [
            'order_number' => 'INB-OLD',
            'phone' => '01711111111',
            'status' => 'delivered',
            'placed_at' => now()->subDays(10),
        ]);
        $this->order($conversation, [
            'order_number' => 'INB-DRAFT',
            'phone' => '01711111111',
            'status' => Order::STATUS_DRAFT,
            'placed_at' => now()->subHour(),
        ]);

        $found = app(InboxRecentOrders::class)->recent($conversation);

        $this->assertSame(['INB-1002', 'INB-1001'], $found->pluck('order_number')->all());
        $this->assertTrue($found->contains('id', $recent->id));
        $this->assertTrue($found->contains('id', $byPhone->id));
    }

    #[Test]
    public function confirm_message_uses_three_day_eta_for_eighty_taka_delivery(): void
    {
        $conversation = $this->conversation();
        $order = $this->order($conversation, [
            'order_number' => 'INB-80',
            'delivery_charge' => 80,
            'subtotal' => 800,
            'total' => 880,
            'due_amount' => 880,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 500,
            'line_total' => 500,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Earrings',
            'quantity' => 1,
            'price' => 300,
            'line_total' => 300,
        ]);

        $body = app(InboxRecentOrders::class)->confirmMessage($order->fresh('items'));

        $this->assertSame(
            "অর্ডার কনফার্ম করার জন্য ধন্যবাদ।\n৩ দিনের মাঝে পাবেন\nবিল: ৫০০ + ৩০০ + ৮০ টাকা ডেলিভারি চার্জ = ৮৮০ টাকা",
            $body,
        );
    }

    #[Test]
    public function confirm_message_uses_three_to_five_day_eta_outside_eighty_taka_areas(): void
    {
        $conversation = $this->conversation();
        $order = $this->order($conversation, [
            'order_number' => 'INB-120',
            'delivery_charge' => 120,
            'subtotal' => 500,
            'total' => 620,
            'due_amount' => 620,
            'area' => 'Chittagong',
            'city' => 'Chittagong',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Ring',
            'quantity' => 2,
            'price' => 250,
            'line_total' => 500,
        ]);

        $body = app(InboxRecentOrders::class)->confirmMessage($order->fresh('items'));

        $this->assertSame(
            "অর্ডার কনফার্ম করার জন্য ধন্যবাদ।\n৩-৫ দিনের মাঝে পাবেন\nবিল: ২৫০ + ২৫০ + ১২০ টাকা ডেলিভারি চার্জ = ৬২০ টাকা",
            $body,
        );
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-recent-orders',
            'customer_name' => 'Shayamoli Hossain',
            'customer_phone' => '01711111111',
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(ChannelConversation $conversation, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'INB-'.random_int(10000, 99999),
            'name' => 'Shayamoli Hossain',
            'phone' => '01711111111',
            'address' => 'Shyamoli, Dhaka',
            'city' => 'Dhaka',
            'area' => 'Mohammadpur',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $conversation->id,
        ], $overrides));
    }
}
