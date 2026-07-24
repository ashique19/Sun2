<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Livewire\Admin\AdminOrders;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Channels\ChannelReplyService;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChannelAiDraftOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function moderatorUser(): User
    {
        Role::findOrCreate('moderator');
        $user = User::factory()->create();
        $user->assignRole('moderator');

        return $user;
    }

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 25,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    private function baseOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Customer',
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
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));
    }

    public function test_draft_ai_segment_lists_only_drafts_and_excludes_from_all(): void
    {
        $this->baseOrder(['status' => 'new', 'order_number' => '1001']);
        $draft = $this->baseOrder([
            'status' => Order::STATUS_DRAFT,
            'order_number' => '1002',
            'placed_via' => Order::PLACED_VIA_MESSENGER,
        ]);
        $this->baseOrder(['status' => 'dispatched', 'order_number' => '1003']);

        $this->assertSame(1, AdminOrderSegment::count('draft-ai'));
        $this->assertSame(1, AdminOrderSegment::count('new'));
        $this->assertSame(2, AdminOrderSegment::count('all'));
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'draft-ai')->whereKey($draft->id)->exists()
        );
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'all')->whereKey($draft->id)->exists()
        );
    }

    public function test_staff_can_open_draft_ai_segment_but_moderator_cannot(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)
            ->get(route('admin.orders.draft-ai'))
            ->assertOk();

        $moderator = $this->moderatorUser();
        $this->actingAs($moderator)
            ->get(route('admin.orders.draft-ai'))
            ->assertForbidden();
    }

    public function test_moderator_cannot_view_draft_order_show(): void
    {
        $draft = $this->baseOrder([
            'status' => Order::STATUS_DRAFT,
            'order_number' => '2001',
            'placed_via' => Order::PLACED_VIA_MESSENGER,
        ]);

        $this->actingAs($this->moderatorUser())
            ->get(route('admin.orders.show', $draft))
            ->assertForbidden();

        $this->actingAs($this->adminUser())
            ->get(route('admin.orders.show', $draft))
            ->assertOk();
    }

    public function test_messenger_webhook_stores_message_and_creates_ai_draft(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'gemini.api_key' => null,
        ]);

        $product = $this->product(['name' => 'Silk Kurti']);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE123',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => 'PSID999'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_test_1',
                        'text' => "Rahim\n01627237432\nHouse 12, Dhanmondi, Dhaka\nSilk Kurti",
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk()->assertSee('EVENT_RECEIVED', false);

        $conversation = ChannelConversation::query()
            ->where('channel', ChannelConversation::CHANNEL_MESSENGER)
            ->where('external_user_id', 'PSID999')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertNotNull($conversation->draft_order_id);

        $draft = Order::query()->find($conversation->draft_order_id);
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isAiDraft());
        $this->assertSame(Order::PLACED_VIA_MESSENGER, $draft->placed_via);
        $this->assertSame('01627237432', $draft->phone);
        $this->assertSame($conversation->id, $draft->channel_conversation_id);
        $this->assertStringContainsString('Draft by AI', (string) $draft->admin_note);

        // Duplicate mid is idempotent — still one message, one draft.
        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $this->assertSame(1, ChannelMessage::query()->count());
        $this->assertSame(1, Order::query()->where('status', Order::STATUS_DRAFT)->count());
        $this->assertSame(25, $product->fresh()->stock_quantity);
    }

    public function test_whatsapp_webhook_creates_draft_from_text(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => '',
            'gemini.api_key' => null,
        ]);

        $this->product(['name' => 'Silk Kurti']);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'phone_number_id' => 'PNID1',
                            'display_phone_number' => '15550001111',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Karim'],
                            'wa_id' => '8801627237432',
                        ]],
                        'messages' => [[
                            'from' => '8801627237432',
                            'id' => 'wamid.TEST1',
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => [
                                'body' => "Karim\n০১৬২৭২৩৭৪৩২\nBanani, Dhaka\nSilk Kurti 2pcs",
                            ],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk()->assertSee('EVENT_RECEIVED', false);

        $conversation = ChannelConversation::query()
            ->where('channel', ChannelConversation::CHANNEL_WHATSAPP)
            ->where('external_user_id', '8801627237432')
            ->first();

        $this->assertNotNull($conversation);
        $draft = Order::query()->find($conversation->draft_order_id);
        $this->assertNotNull($draft);
        $this->assertSame(Order::STATUS_DRAFT, $draft->status);
        $this->assertSame(Order::PLACED_VIA_WHATSAPP, $draft->placed_via);
        $this->assertSame('WhatsApp', $draft->placedByLabel());
        $this->assertSame('Karim', $draft->name);
    }

    public function test_confirm_draft_moves_to_new_reserves_stock_and_clears_draft_pointer(): void
    {
        $product = $this->product(['stock_quantity' => 10]);
        $admin = $this->adminUser();

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_CONFIRM',
            'last_inbound_at' => now(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_confirm',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Nila\n01627237432\nMirpur, Dhaka\n{$product->name}",
            'sent_at' => now(),
        ]);

        config(['gemini.api_key' => null]);

        $draft = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertTrue($draft->isAiDraft());
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $confirmed = app(ChannelOrderDraftService::class)->confirm($draft, $admin->id);

        $this->assertSame('new', $confirmed->status);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertNull($conversation->fresh()->draft_order_id);
        $this->assertSame($conversation->id, $confirmed->channel_conversation_id);
        $this->assertTrue(
            AdminOrderSegment::apply(Order::query(), 'new')->whereKey($confirmed->id)->exists()
        );
        $this->assertFalse(
            AdminOrderSegment::apply(Order::query(), 'draft-ai')->whereKey($confirmed->id)->exists()
        );
    }

    public function test_livewire_confirm_draft_from_list(): void
    {
        $admin = $this->adminUser();
        $product = $this->product(['stock_quantity' => 5]);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_LW',
            'last_inbound_at' => now(),
        ]);

        $draft = $this->baseOrder([
            'status' => Order::STATUS_DRAFT,
            'order_number' => '3001',
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $conversation->id,
            'subtotal' => 1200,
            'total' => 1200,
            'cod_amount' => 1200,
            'due_amount' => 1200,
        ]);
        $draft->items()->create([
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
        $conversation->update(['draft_order_id' => $draft->id]);

        Livewire::actingAs($admin)
            ->test(AdminOrders::class, ['segment' => 'draft-ai'])
            ->call('confirmDraft', $draft->id)
            ->assertHasNoErrors();

        $this->assertSame('new', $draft->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_conversation_viewer_and_reply_within_window(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message_id' => 'm_out_1'], 200),
        ]);

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        $admin = $this->adminUser();
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_REPLY',
            'last_inbound_at' => now()->subHour(),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello',
            'sent_at' => now()->subHour(),
        ]);

        $draft = $this->baseOrder([
            'status' => Order::STATUS_DRAFT,
            'order_number' => '4001',
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $conversation->id,
        ]);
        $conversation->update(['draft_order_id' => $draft->id]);

        Livewire::actingAs($admin)
            ->test(AdminOrderShow::class, ['order' => $draft])
            ->call('toggleConversation')
            ->assertSet('showConversation', true)
            ->set('replyText', 'Thanks, confirming your order.')
            ->call('sendConversationReply')
            ->assertHasNoErrors()
            ->assertSet('message', 'Reply sent.');

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertTrue(
            $conversation->messages()->where('direction', ChannelMessage::DIRECTION_OUTBOUND)->exists()
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/messages'));
    }

    public function test_reply_blocked_outside_24h_window(): void
    {
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_OLD',
            'last_inbound_at' => now()->subHours(25),
        ]);

        $result = app(ChannelReplyService::class)->sendText($conversation, 'Too late');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['outside_window']);
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_gemini_parse_path_used_when_configured(): void
    {
        $product = $this->product(['name' => 'Photo Kurti']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')->once()->andReturn([
            'name' => 'Sajida',
            'phone' => '01712345678',
            'address' => 'Road 4, Gulshan',
            'city' => 'Dhaka',
            'area' => 'Gulshan',
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
        ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $conversation = app(ChannelConversationService::class)->findOrCreate(
            ChannelConversation::CHANNEL_MESSENGER,
            'PSID_GEM',
        );
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_gem',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'please send this',
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertSame('Sajida', $draft->name);
        $this->assertSame('01712345678', $draft->phone);
        $this->assertSame(2, (int) $draft->items->first()->quantity);
        $this->assertSame($product->id, (int) $draft->items->first()->product_id);
        $this->assertSame('gemini', $draft->ai_parse_meta['source'] ?? null);
    }

    public function test_discard_draft_does_not_change_stock(): void
    {
        $product = $this->product(['stock_quantity' => 8]);
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_WHATSAPP,
            'external_user_id' => '8801',
            'last_inbound_at' => now(),
        ]);

        $draft = $this->baseOrder([
            'status' => Order::STATUS_DRAFT,
            'order_number' => '5001',
            'placed_via' => Order::PLACED_VIA_WHATSAPP,
            'channel_conversation_id' => $conversation->id,
        ]);
        $draft->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 3,
            'price' => 1200,
            'purchase_price' => 500,
            'base_price' => 1200,
            'line_total' => 3600,
            'commission_rate' => 0,
            'commission_earned' => 0,
        ]);
        $conversation->update(['draft_order_id' => $draft->id]);

        app(ChannelOrderDraftService::class)->discard($draft);

        $this->assertNull(Order::query()->find($draft->id));
        $this->assertNull($conversation->fresh()->draft_order_id);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }
}
