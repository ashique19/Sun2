<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Channels\ChannelOrderDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxOrderMappingTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(array $overrides = []): ChannelConversation
    {
        return ChannelConversation::query()->create(array_merge([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-map-1',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function toggle_order_panel_creates_a_staff_draft(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('orderPanelOpen', false)
            ->call('toggleOrderPanel')
            ->assertSet('orderPanelOpen', true)
            ->assertSee('draft')
            ->assertSeeHtml('aria-label="Order fields"');

        $conversation->refresh();
        $this->assertNotNull($conversation->draft_order_id);

        $order = Order::query()->find($conversation->draft_order_id);
        $this->assertNotNull($order);
        $this->assertTrue($order->isAiDraft());
        $this->assertSame('staff', $order->ai_parse_meta['source'] ?? null);
    }

    #[Test]
    public function mapping_a_message_to_phone_name_and_address_updates_the_draft(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $phoneMessage = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => '01712345678',
            'sent_at' => now()->subMinutes(3),
        ]);
        $nameMessage = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Rahim Uddin',
            'sent_at' => now()->subMinutes(2),
        ]);
        $addressMessage = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'House 12, Road 5, Dhanmondi',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $phoneMessage->id)
            ->call('beginMapField', 'phone')
            ->assertSet('statusMessage', 'Added to order phone.')
            ->call('openMessageMapMenu', $nameMessage->id)
            ->call('beginMapField', 'name')
            ->assertSet('statusMessage', 'Added to order name.')
            ->call('openMessageMapMenu', $addressMessage->id)
            ->call('beginMapField', 'address')
            ->assertSet('statusMessage', 'Added to order address.')
            ->assertSee('Rahim Uddin')
            ->assertSee('01712345678')
            ->assertSee('House 12, Road 5, Dhanmondi');

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame('Rahim Uddin', $order->name);
        $this->assertSame('01712345678', $order->phone);
        $this->assertSame('House 12, Road 5, Dhanmondi', $order->address);
        $this->assertContains('phone', $order->ai_parse_meta['staff_locked_fields']);
        $this->assertContains('name', $order->ai_parse_meta['staff_locked_fields']);
        $this->assertContains('address', $order->ai_parse_meta['staff_locked_fields']);
    }

    #[Test]
    public function mapping_uses_selected_text_instead_of_the_whole_message(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "নাম:রূনলে ম্রো\nঠিকানা:বান্দরবান।\nমোবাইল :01810992298",
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id, 'রূনলে ম্রো')
            ->assertSet('mappingSelectedText', 'রূনলে ম্রো')
            ->call('beginMapField', 'name')
            ->assertSet('statusMessage', 'Added to order name.')
            ->call('openMessageMapMenu', $message->id, 'বান্দরবান।')
            ->call('beginMapField', 'address')
            ->assertSet('statusMessage', 'Added to order address.')
            ->call('openMessageMapMenu', $message->id, '01810992298')
            ->call('beginMapField', 'phone')
            ->assertSet('statusMessage', 'Added to order phone.');

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame('রূনলে ম্রো', $order->name);
        $this->assertSame('বান্দরবান।', $order->address);
        $this->assertSame('01810992298', $order->phone);
        $this->assertNotSame($message->body, $order->name);
        $this->assertNotSame($message->body, $order->address);
    }

    #[Test]
    public function mapping_without_selection_still_uses_the_full_message_for_address(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $body = "নাম:রূনলে ম্রো\nঠিকানা:বান্দরবান।\nমোবাইল :01810992298";
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => $body,
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id)
            ->assertSet('mappingSelectedText', null)
            ->call('beginMapField', 'address')
            ->assertSet('statusMessage', 'Added to order address.');

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame($body, $order->address);
    }

    #[Test]
    public function mapping_a_message_to_product_attaches_catalog_line(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Sun Dual Saree',
            'slug' => 'sun-dual-saree-'.uniqid(),
            'sku' => 'SDS'.random_int(100, 999),
            'price' => 1500,
            'purchase_price' => 800,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Sun Dual Saree please',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id)
            ->call('beginMapField', 'product')
            ->assertSet('mappingField', 'product')
            ->call('applyMapField', 'product', $product->id)
            ->assertSet('statusMessage', 'Added to order product.')
            ->assertSee('Sun Dual Saree');

        $order = Order::query()->with('items')->find($conversation->fresh()->draft_order_id);
        $this->assertCount(1, $order->items);
        $this->assertSame($product->id, $order->items->first()->product_id);
        $this->assertSame('Sun Dual Saree', $order->items->first()->name);
    }

    #[Test]
    public function staff_locked_fields_survive_ai_resync(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation([
            'customer_phone' => '01712345678',
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Staff Locked Name',
            'sent_at' => now(),
        ]);

        $drafts = app(ChannelOrderDraftService::class);
        $drafts->applyMessageToField($conversation, $message, 'name', null, auth()->id());

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame('Staff Locked Name', $order->name);

        // Simulate an AI re-sync that would otherwise overwrite the name.
        $order->update([
            'ai_parse_meta' => array_merge($order->ai_parse_meta ?? [], [
                'staff_locked_fields' => ['name'],
            ]),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "01712345678\nSomeone Else\nBanani house 1\nWant a saree",
            'sent_at' => now(),
        ]);

        $drafts->syncDraftFromConversation($conversation->fresh());

        $this->assertSame('Staff Locked Name', $order->fresh()->name);
    }

    #[Test]
    public function quick_reply_chip_inserts_body_into_composer(): void
    {
        config([
            'channels.inbox.quick_replies' => [
                ['label' => 'Hi', 'body' => 'Hello from chip'],
            ],
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Hi')
            ->call('insertQuickReply', 0)
            ->assertSet('replyText', 'Hello from chip')
            ->call('insertQuickReply', 0)
            ->assertSet('replyText', "Hello from chip\nHello from chip");
    }
}
