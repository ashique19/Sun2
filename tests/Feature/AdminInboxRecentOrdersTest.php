<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\InboxCustomerInvoiceImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Gold Necklace',
            'quantity' => 1,
            'price' => 900,
            'line_total' => 900,
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openCustomerInvoicePreview', $order->id)
            ->assertHasNoErrors()
            ->assertSet('customerInvoicePreviewOrderId', $order->id)
            ->assertSeeHtml('data-inbox-customer-invoice-preview')
            ->assertSeeHtml('data-inbox-customer-invoice-image')
            ->assertSeeHtml('wire:click="sendCustomerInvoiceImage"')
            ->assertSee('Detailed invoice for order INB-8800')
            ->assertDontSee('Open print page')
            ->assertDontSeeHtml('<iframe');

        $path = $component->get('customerInvoicePreviewPath');
        $this->assertIsString($path);
        $absolute = public_path(ltrim($path, '/'));
        $this->assertFileExists($absolute);
        $info = getimagesize($absolute);
        $this->assertIsArray($info);
        $this->assertSame(InboxCustomerInvoiceImageService::CANVAS_WIDTH, $info[0]);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertStringContainsString(e(ltrim($path, '/')), $component->html());

        $component
            ->call('closeCustomerInvoicePreview')
            ->assertSet('customerInvoicePreviewOrderId', null)
            ->assertSet('customerInvoicePreviewPath', null)
            ->assertDontSeeHtml('data-inbox-customer-invoice-preview');

        $history = file_get_contents(resource_path('js/inbox-thread-history.js'));
        $this->assertIsString($history);
        $this->assertStringContainsString('[data-inbox-customer-invoice-preview]', $history);

        File::delete($absolute);
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

    #[Test]
    public function customer_invoice_image_can_be_sent_to_the_thread(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-header-invoice-send'], 200)
                ->push(['message_id' => 'm_invoice_1'], 200)
                ->push(['recipient_id' => 'psid-header-invoice-send'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-header-invoice-send',
            'customer_name' => 'Invoice Send',
            'customer_phone' => '01788888888',
            'last_inbound_at' => now(),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_invoice',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Please send bill',
            'sent_at' => now()->subMinute(),
        ]);

        $order = Order::query()->create([
            'order_number' => 'INB-8900',
            'name' => 'Invoice Send',
            'phone' => '01788888888',
            'address' => 'Mirpur',
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
            'status' => 'confirmed',
            'placed_at' => now()->subHour(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'channel_conversation_id' => $conversation->id,
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Silver Ring',
            'quantity' => 1,
            'price' => 1200,
            'line_total' => 1200,
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openCustomerInvoicePreview', $order->id)
            ->assertSet('customerInvoicePreviewOrderId', $order->id)
            ->call('sendCustomerInvoiceImage')
            ->assertHasNoErrors()
            ->assertSet('customerInvoicePreviewOrderId', null)
            ->assertSet('customerInvoicePreviewPath', null)
            ->assertSet('statusMessage', 'Customer invoice sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertNotNull($outbound->media_url);
        $this->assertTrue(is_file(public_path($outbound->media_url)));
        $this->assertNull($outbound->body);

        Http::assertSent(function ($request) {
            $message = $request['message'] ?? null;

            return is_array($message)
                && ($message['attachment']['type'] ?? null) === 'image'
                && filled($message['attachment']['payload']['url'] ?? null);
        });

        $preview = public_path(InboxCustomerInvoiceImageService::RELATIVE_DIRECTORY.'/'.$order->id.'.jpg');
        File::delete($preview);
        if (is_string($outbound->media_url)) {
            File::delete(public_path(ltrim($outbound->media_url, '/')));
        }
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
