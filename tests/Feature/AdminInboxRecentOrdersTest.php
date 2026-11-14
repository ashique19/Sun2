<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Support\OrderPrintVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxRecentOrdersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function thread_header_lists_recent_order_numbers_and_fills_confirm_message(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-header-orders',
            'customer_name' => 'Shayamoli Hossain',
            'customer_phone' => '01722222222',
            'last_inbound_at' => now(),
        ]);

        $order = Order::query()->create([
            'order_number' => 'INB-7788',
            'name' => 'Shayamoli Hossain',
            'phone' => '01722222222',
            'address' => 'Shyamoli',
            'city' => 'Dhaka',
            'area' => 'Shyamoli',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'confirmed',
            'placed_at' => now()->subHours(3),
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'channel_conversation_id' => $conversation->id,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 500,
            'line_total' => 500,
        ]);

        $second = Order::query()->create([
            'order_number' => 'INB-7799',
            'name' => 'Shayamoli Hossain',
            'phone' => '01722222222',
            'address' => 'Shyamoli',
            'city' => 'Dhaka',
            'subtotal' => 300,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 380,
            'cod_amount' => 380,
            'due_amount' => 380,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now()->subHour(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'channel_conversation_id' => $conversation->id,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-recent-orders')
            ->assertSeeHtml('data-inbox-recent-order-trigger="'.$order->id.'"')
            ->assertSeeHtml('x-teleport="body"')
            ->assertSee('INB-7788')
            ->assertSee('INB-7799')
            ->assertSeeHtml(',')
            ->assertSeeHtml('href="'.e(route('admin.orders.show', $order)).'"')
            ->assertSeeHtml('href="'.e(route('admin.orders.show', $second)).'"')
            ->assertSee('Order details')
            ->assertSee('Order confirm msg')
            ->assertSee('Customer invoice')
            ->assertSeeHtml('$wire.openCustomerInvoicePreview('.$order->id.')')
            ->call('fillOrderConfirmMessage', $order->id)
            ->assertSet(
                'replyText',
                "ধন্যবাদ।\n৩ দিনের মাঝে পাবেন\nবিল: ৫০০ + ৮০ টাকা ডেলিভারি চার্জ = ৫৮০ টাকা",
            );
    }

    #[Test]
    public function fill_order_confirm_message_rejects_orders_outside_the_thread(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-header-other',
            'customer_name' => 'Other',
            'customer_phone' => '01733333333',
            'last_inbound_at' => now(),
        ]);

        $foreign = Order::query()->create([
            'order_number' => 'INB-FOREIGN',
            'name' => 'Stranger',
            'phone' => '01744444444',
            'address' => 'Elsewhere',
            'city' => 'Dhaka',
            'subtotal' => 100,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 180,
            'cod_amount' => 180,
            'due_amount' => 180,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertDontSee('INB-FOREIGN')
            ->call('fillOrderConfirmMessage', $foreign->id)
            ->assertSet('replyText', '')
            ->assertSet('error', 'That order is not in this conversation’s last 7 days.');
    }

    #[Test]
    public function thread_header_previews_the_detailed_customer_invoice(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-header-invoice',
            'customer_name' => 'Invoice Customer',
            'customer_phone' => '01755555555',
            'last_inbound_at' => now(),
        ]);

        $order = Order::query()->create([
            'order_number' => 'INB-8800',
            'name' => 'Invoice Customer',
            'phone' => '01755555555',
            'address' => 'Dhanmondi',
            'city' => 'Dhaka',
            'area' => 'Dhanmondi',
            'subtotal' => 900,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 980,
            'cod_amount' => 980,
            'due_amount' => 980,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'confirmed',
            'placed_at' => now()->subHour(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'channel_conversation_id' => $conversation->id,
        ]);

        $previewUrl = route('admin.orders.print', [
            'order' => $order,
            'variant' => OrderPrintVariant::CustomerDetailed->value,
            'embed' => 1,
        ]);
        $printUrl = route('admin.orders.print', [
            'order' => $order,
            'variant' => OrderPrintVariant::CustomerDetailed->value,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openCustomerInvoicePreview', $order->id)
            ->assertHasNoErrors()
            ->assertSet('customerInvoicePreviewOrderId', $order->id)
            ->assertSeeHtml('data-inbox-customer-invoice-preview')
            ->assertSeeHtml('src="'.e($previewUrl).'"')
            ->assertSeeHtml('href="'.e($printUrl).'"')
            ->assertSee('Detailed invoice for order INB-8800')
            ->call('closeCustomerInvoicePreview')
            ->assertSet('customerInvoicePreviewOrderId', null)
            ->assertDontSeeHtml('data-inbox-customer-invoice-preview');

        $history = file_get_contents(resource_path('js/inbox-thread-history.js'));
        $this->assertIsString($history);
        $this->assertStringContainsString('[data-inbox-customer-invoice-preview]', $history);
    }

    #[Test]
    public function customer_invoice_preview_rejects_orders_outside_the_thread(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-header-invoice-other',
            'customer_name' => 'Other',
            'customer_phone' => '01766666666',
            'last_inbound_at' => now(),
        ]);

        $foreign = Order::query()->create([
            'order_number' => 'INB-FOREIGN-INV',
            'name' => 'Stranger',
            'phone' => '01777777777',
            'address' => 'Elsewhere',
            'city' => 'Dhaka',
            'subtotal' => 100,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 180,
            'cod_amount' => 180,
            'due_amount' => 180,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openCustomerInvoicePreview', $foreign->id)
            ->assertSet('customerInvoicePreviewOrderId', null)
            ->assertDontSeeHtml('data-inbox-customer-invoice-preview')
            ->assertSet('error', 'That order is not in this conversation’s last 7 days.');
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
