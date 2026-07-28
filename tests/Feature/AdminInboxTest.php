<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Channels\ChannelReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxTest extends TestCase
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
            'external_user_id' => 'psid-1',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function opening_a_conversation_marks_it_read(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation(['last_read_at' => null]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('selectedConversationId', $conversation->id);

        $this->assertNotNull($conversation->fresh()->last_read_at);
    }

    #[Test]
    public function selecting_a_conversation_opens_the_mobile_thread_pane(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::test(AdminInbox::class)
            ->assertSet('mobileThreadOpen', false)
            ->assertSee('Filters')
            ->call('selectConversation', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            ->assertSeeHtml('aria-label="Back to conversations"')
            ->assertSeeHtml('fixed inset-0 z-30')
            ->assertSeeHtml('aria-label="Send"')
            ->call('closeMobileThread')
            ->assertSet('mobileThreadOpen', false)
            ->assertSet('selectedConversationId', null);
    }

    #[Test]
    public function closing_mobile_thread_clears_conversation_query_param(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        // Explicit open sets ?conversation= via the Url attribute.
        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            ->call('closeMobileThread')
            ->assertSet('selectedConversationId', null)
            ->assertSet('mobileThreadOpen', false);

        // Fresh load without ?conversation= stays on the list (no auto-URL selection).
        Livewire::test(AdminInbox::class)
            ->assertSet('selectedConversationId', null)
            ->assertSet('mobileThreadOpen', false)
            ->assertSee('Conversations');

        // Deep link still opens the thread.
        Livewire::withQueryParams(['conversation' => $conversation->id])
            ->test(AdminInbox::class)
            ->assertSet('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true);
    }

    #[Test]
    public function conversation_url_uses_push_history_so_browser_back_returns_to_list(): void
    {
        $attribute = (new \ReflectionProperty(AdminInbox::class, 'selectedConversationId'))
            ->getAttributes(Url::class)[0] ?? null;

        $this->assertNotNull($attribute);
        $instance = $attribute->newInstance();
        $this->assertTrue($instance->history);
        $this->assertSame('conversation', $instance->as);
    }

    #[Test]
    public function clearing_conversation_id_closes_mobile_thread_pane(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            // Simulates browser Back restoring /admin/inbox without ?conversation=
            ->set('selectedConversationId', null)
            ->assertSet('mobileThreadOpen', false);
    }

    #[Test]
    public function mobile_filters_toggle_and_stay_open_when_filters_are_active(): void
    {
        $this->actingAs($this->adminUser());
        $this->conversation();

        Livewire::test(AdminInbox::class)
            ->assertSet('mobileFiltersOpen', false)
            ->call('toggleMobileFilters')
            ->assertSet('mobileFiltersOpen', true)
            ->set('unread', '1')
            ->assertSet('mobileFiltersOpen', true);

        Livewire::withQueryParams(['unread' => '1'])
            ->test(AdminInbox::class)
            ->assertSet('mobileFiltersOpen', true)
            ->assertSet('unread', '1');
    }

    #[Test]
    public function deep_linked_conversation_opens_mobile_thread(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::withQueryParams(['conversation' => $conversation->id])
            ->test(AdminInbox::class)
            ->assertSet('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            ->assertSeeHtml('aria-label="Back to conversations"');
    }

    #[Test]
    public function conversation_list_shows_customer_without_channel_badge(): void
    {
        $this->actingAs($this->adminUser());
        $this->conversation([
            'customer_name' => 'Compact Row Customer',
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'last_read_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->assertSee('Compact Row Customer')
            ->assertDontSeeHtml('>MSG</span>')
            ->assertDontSeeHtml('>WA</span>');
    }

    #[Test]
    public function it_can_send_a_reply_from_the_inbox(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello',
            'sent_at' => now(),
        ]);

        $replies = Mockery::mock(ChannelReplyService::class);
        $replies->shouldReceive('sendText')
            ->once()
            ->andReturn([
                'ok' => true,
                'message' => null,
                'error' => null,
                'outside_window' => false,
            ]);
        $replies->shouldReceive('markSeen')->zeroOrMoreTimes();
        $this->app->instance(ChannelReplyService::class, $replies);

        Livewire::test(AdminInbox::class)
            ->set('selectedConversationId', $conversation->id)
            ->set('replyText', 'Thanks!')
            ->call('sendReply')
            ->assertSet('replyText', '')
            ->assertSet('statusMessage', 'Reply sent.');
    }

    #[Test]
    public function empty_inbox_explains_status_instead_of_silent_blank(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.verify_token' => '',
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => '',
            'app.url' => 'https://example.test',
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->assertSee('Inbox status')
            ->assertSee('No conversations stored yet')
            ->assertSee('/api/webhooks/messenger')
            ->assertSee('Verify token configured')
            ->assertSee('Development mode')
            ->assertSee('Sync from Facebook');
    }

    #[Test]
    public function filtered_empty_inbox_offers_clear_filters_when_rows_exist(): void
    {
        $this->actingAs($this->adminUser());
        $this->conversation();

        Livewire::test(AdminInbox::class)
            ->set('channel', 'whatsapp')
            ->assertSee('Clear filters')
            ->assertSee('No conversations match the current filters')
            ->call('clearFilters')
            ->assertSet('channel', '')
            ->assertSee('Karim');
    }

    #[Test]
    public function refresh_inbox_picks_up_new_inbound_messages_without_reclicking(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation(['last_read_at' => now()->subMinute()]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'First hello',
            'sent_at' => now()->subMinutes(2),
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('First hello');

        $conversation->update(['last_inbound_at' => now()]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Fresh reply from customer',
            'sent_at' => now(),
        ]);

        $component->call('refreshInbox')
            ->assertSee('First hello')
            ->assertSee('Fresh reply from customer');

        $this->assertNotNull($conversation->fresh()->last_read_at);
        $this->assertTrue($conversation->fresh()->last_read_at->gte(now()->subMinute()));
    }

    #[Test]
    public function inbox_shows_image_thumbnails_via_staff_media_proxy(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=123',
            'media_mime' => 'image/jpeg',
            'raw_payload' => [
                'message' => [
                    'attachments' => [
                        ['type' => 'image', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=123']],
                    ],
                ],
            ],
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Photo')
            ->assertSee(route('admin.inbox.media', $message), false);
    }

    #[Test]
    public function staff_can_stream_channel_message_media_through_proxy(): void
    {
        config(['facebook.messenger.page_access_token' => 'page-token']);

        Http::fake([
            'lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=123',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $this->get(route('admin.inbox.media', $message))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('fake-image-bytes', false);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'lookaside.fbsbx.com')
                && $request->hasHeader('Authorization', 'Bearer page-token');
        });
    }

    #[Test]
    public function opening_a_conversation_marks_messenger_seen(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/messages*' => Http::response(['recipient_id' => 'psid-1'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation([
            'last_read_at' => null,
            'last_inbound_at' => now()->subMinute(),
            'messenger_seen_at' => null,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('selectedConversationId', $conversation->id);

        $this->assertNotNull($conversation->fresh()->last_read_at);
        $this->assertNotNull($conversation->fresh()->messenger_seen_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PAGE42/messages')
                && ($request['sender_action'] ?? null) === 'mark_seen'
                && ($request['recipient']['id'] ?? null) === 'psid-1';
        });
    }

    #[Test]
    public function refresh_retries_messenger_seen_after_graph_failure(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/messages*' => Http::sequence()
                ->push(['error' => ['message' => 'Thread ownership required', 'code' => 10]], 400)
                ->push(['error' => ['message' => 'Thread ownership required', 'code' => 10]], 400)
                ->push(['recipient_id' => 'psid-1'], 200),
            'https://graph.facebook.com/v25.0/PAGE42/take_thread_control*' => Http::sequence()
                ->push(['error' => ['message' => 'busy']], 400)
                ->push(['success' => true], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation([
            'last_read_at' => null,
            'last_inbound_at' => now()->subMinute(),
            'messenger_seen_at' => null,
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id);

        $this->assertNotNull($conversation->fresh()->last_read_at);
        $this->assertNull($conversation->fresh()->messenger_seen_at);

        $component->call('refreshInbox');

        $this->assertNotNull($conversation->fresh()->messenger_seen_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/take_thread_control');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PAGE42/messages')
                && ($request['sender_action'] ?? null) === 'mark_seen';
        });
    }

    #[Test]
    public function deep_linked_conversation_marks_messenger_seen_on_mount(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/messages*' => Http::response(['recipient_id' => 'psid-1'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation([
            'last_read_at' => null,
            'last_inbound_at' => now()->subMinute(),
            'messenger_seen_at' => null,
        ]);

        Livewire::withQueryParams(['conversation' => $conversation->id])
            ->test(AdminInbox::class)
            ->assertSet('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true);

        $this->assertNotNull($conversation->fresh()->last_read_at);
        $this->assertNotNull($conversation->fresh()->messenger_seen_at);
    }

    #[Test]
    public function it_can_reply_to_a_previous_message(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-1'], 200)
                ->push(['message_id' => 'm_out_1'], 200)
                ->push(['recipient_id' => 'psid-1'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Do you have this in gold?',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('setReplyTo', $inbound->id)
            ->assertSet('replyToMessageId', $inbound->id)
            ->set('replyText', 'Yes, in stock')
            ->call('sendReply')
            ->assertSet('replyText', '')
            ->assertSet('replyToMessageId', null)
            ->assertSet('statusMessage', 'Reply sent.');

        $this->assertDatabaseHas('channel_messages', [
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => 'Yes, in stock',
            'reply_to_message_id' => $inbound->id,
        ]);

        Http::assertSent(function ($request) {
            $message = $request['message'] ?? null;

            return is_array($message)
                && ($message['text'] ?? null) === 'Yes, in stock'
                && ($message['reply_to']['mid'] ?? null) === 'm_in_1';
        });
    }

    #[Test]
    public function it_can_attach_and_send_an_image_reply(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-1'], 200)
                ->push(['message_id' => 'm_img_1'], 200)
                ->push(['message_id' => 'm_caption_1'], 200)
                ->push(['recipient_id' => 'psid-1'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_img',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Send photo',
            'sent_at' => now()->subMinute(),
        ]);

        $file = UploadedFile::fake()->image('reply.jpg', 40, 40);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->set('replyImage', $file)
            ->set('replyText', 'Here you go')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Reply sent.')
            ->assertSet('replyImage', null);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertNotNull($outbound->media_url);
        $this->assertSame('Here you go', $outbound->body);
        $this->assertTrue(is_file(public_path($outbound->media_url)));

        Http::assertSent(function ($request) {
            $message = $request['message'] ?? null;

            return is_array($message)
                && ($message['attachment']['type'] ?? null) === 'image'
                && filled($message['attachment']['payload']['url'] ?? null);
        });
    }

    #[Test]
    public function lists_all_conversations_not_just_one(): void
    {
        $this->actingAs($this->adminUser());

        $first = $this->conversation([
            'external_user_id' => 'psid-list-1',
            'customer_name' => 'Alpha Customer',
            'last_inbound_at' => now()->subMinutes(3),
        ]);
        $second = $this->conversation([
            'external_user_id' => 'psid-list-2',
            'customer_name' => 'Beta Customer',
            'last_inbound_at' => now()->subMinutes(2),
        ]);
        $third = $this->conversation([
            'external_user_id' => 'psid-list-3',
            'customer_name' => 'Gamma Customer',
            'last_inbound_at' => now()->subMinute(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $first->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello from Alpha',
            'sent_at' => now()->subMinutes(3),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $second->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello from Beta',
            'sent_at' => now()->subMinutes(2),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $third->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello from Gamma',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->assertSee('Alpha Customer')
            ->assertSee('Beta Customer')
            ->assertSee('Gamma Customer')
            ->assertSee('Hello from Alpha')
            ->assertSee('Hello from Beta')
            ->assertSee('Hello from Gamma')
            ->assertSee('3 shown');
    }

    #[Test]
    public function active_filters_do_not_hide_total_count_and_can_be_cleared_to_restore_list(): void
    {
        $this->actingAs($this->adminUser());

        $this->conversation([
            'external_user_id' => 'psid-a',
            'customer_name' => 'Visible Unread',
            'last_inbound_at' => now(),
            'last_read_at' => null,
        ]);
        $this->conversation([
            'external_user_id' => 'psid-b',
            'customer_name' => 'Already Read',
            'last_inbound_at' => now()->subHour(),
            'last_read_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->set('unread', '1')
            ->assertSee('Visible Unread')
            ->assertDontSee('Already Read')
            ->assertSee('Showing 1 of 2 conversations')
            ->call('clearFilters')
            ->assertSee('Visible Unread')
            ->assertSee('Already Read')
            ->assertSee('2 shown');
    }

    #[Test]
    public function sync_from_facebook_imports_graph_conversations(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_SYNC_1', 'name' => 'Synced Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_sync_1',
                            'message' => 'Imported hello',
                            'from' => ['id' => 'PSID_SYNC_1', 'name' => 'Synced Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->call('syncFromFacebook')
            ->assertSet('error', null)
            ->assertSee('Imported hello')
            ->assertSee('Synced 1 conversation');

        $this->assertDatabaseHas('channel_conversations', [
            'channel' => 'messenger',
            'external_user_id' => 'PSID_SYNC_1',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_sync_1',
            'body' => 'Imported hello',
            'direction' => 'inbound',
        ]);
    }

    #[Test]
    public function poll_sync_from_facebook_imports_quietly_while_inbox_is_open(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_poll_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_POLL_1', 'name' => 'Polled Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_poll_1',
                            'message' => 'Polled hello',
                            'from' => ['id' => 'PSID_POLL_1', 'name' => 'Polled Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->call('pollSyncFromFacebook')
            ->assertSet('error', null)
            ->assertSet('statusMessage', null)
            ->assertNotSet('lastSyncedAt', null)
            ->assertSet('syncToast', null)
            ->assertSee('Polled hello')
            ->assertSeeHtml('wire:poll.10s.visible="pollSyncFromFacebook"')
            ->assertSeeHtml('fixed bottom-0 left-0')
            ->assertSee('Last synced');

        $this->assertDatabaseHas('channel_conversations', [
            'channel' => 'messenger',
            'external_user_id' => 'PSID_POLL_1',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_poll_1',
            'body' => 'Polled hello',
        ]);
    }

    #[Test]
    public function poll_sync_failure_sets_toast_without_status_message(): void
    {
        config([
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => '',
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->call('pollSyncFromFacebook')
            ->assertSet('statusMessage', null)
            ->assertSet('error', null)
            ->assertSet('lastSyncedAt', null)
            ->assertSet('syncToast', 'Facebook Page access token or Page ID is not configured.')
            ->assertSee('Sync failed:')
            ->call('dismissSyncToast')
            ->assertSet('syncToast', null);
    }

    #[Test]
    public function restoring_conversation_from_url_marks_it_read(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation(['last_read_at' => null]);

        // Simulates browser Forward / history restoring ?conversation=
        Livewire::test(AdminInbox::class)
            ->assertSet('selectedConversationId', null)
            ->set('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true);

        $this->assertNotNull($conversation->fresh()->last_read_at);
    }

    #[Test]
    public function poll_sync_updates_open_conversation_from_query_string(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        $this->actingAs($this->adminUser());

        $conversation = $this->conversation([
            'external_user_id' => 'PSID_OPEN_1',
            'customer_name' => 'Open Thread Customer',
            'last_read_at' => now()->subHour(),
            'messenger_seen_at' => now()->subHour(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_open_old',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Earlier message',
            'sent_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_open_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_OPEN_1', 'name' => 'Open Thread Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [
                            [
                                'id' => 'm_open_new',
                                'message' => 'New while thread open',
                                'from' => ['id' => 'PSID_OPEN_1', 'name' => 'Open Thread Customer'],
                                'created_time' => now()->subSecond()->toIso8601String(),
                            ],
                            [
                                'id' => 'm_open_old',
                                'message' => 'Earlier message',
                                'from' => ['id' => 'PSID_OPEN_1', 'name' => 'Open Thread Customer'],
                                'created_time' => now()->subHour()->toIso8601String(),
                            ],
                        ],
                    ],
                ]],
            ], 200),
            'https://graph.facebook.com/v25.0/PAGE42/messages*' => Http::response(['recipient_id' => 'PSID_OPEN_1'], 200),
        ]);

        Livewire::withQueryParams(['conversation' => $conversation->id])
            ->test(AdminInbox::class)
            ->assertSet('selectedConversationId', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            ->assertSee('Earlier message')
            ->assertSeeHtml('aria-label="Sync from Facebook"')
            ->assertSeeHtml('wire:poll.10s.visible="pollSyncFromFacebook"')
            ->assertSeeHtml('fixed bottom-0 left-0')
            ->assertSeeHtml('wire:key="thread-'.$conversation->id.'"')
            ->call('pollSyncFromFacebook')
            ->assertSee('Earlier message')
            ->assertSee('New while thread open')
            ->assertNotSet('lastSyncedAt', null);

        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_open_new',
            'body' => 'New while thread open',
        ]);
    }

    #[Test]
    public function thread_header_shows_messenger_seen_pending_when_graph_lags(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/messages*' => Http::response(['error' => ['message' => 'busy']], 400),
            'https://graph.facebook.com/v25.0/PAGE42/take_thread_control*' => Http::response(['error' => ['message' => 'busy']], 400),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation([
            'last_inbound_at' => now()->subMinute(),
            'messenger_seen_at' => null,
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Messenger seen pending');
    }

    #[Test]
    public function open_thread_initially_hides_messages_older_than_lookback_window(): void
    {
        config(['channels.inbox.thread_lookback_hours' => 24]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Ancient greeting',
            'sent_at' => now()->subHours(30),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Recent greeting',
            'sent_at' => now()->subHour(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Recent greeting')
            ->assertDontSee('Ancient greeting')
            ->assertSee('load older messages')
            ->assertSet('threadHistoryExpanded', false);
    }

    #[Test]
    public function load_older_thread_history_reveals_messages_beyond_lookback(): void
    {
        config(['channels.inbox.thread_lookback_hours' => 24]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Ancient greeting',
            'sent_at' => now()->subDays(2),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Recent greeting',
            'sent_at' => now()->subHour(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertDontSee('Ancient greeting')
            ->call('loadOlderThreadHistory')
            ->assertSet('threadHistoryExpanded', true)
            ->assertSee('Ancient greeting')
            ->assertSee('Recent greeting')
            ->assertSee('Showing full conversation history')
            ->assertDontSee('load older messages');
    }

    #[Test]
    public function switching_conversations_resets_thread_history_expansion(): void
    {
        $this->actingAs($this->adminUser());
        $first = $this->conversation(['external_user_id' => 'psid-a', 'customer_name' => 'First']);
        $second = $this->conversation(['external_user_id' => 'psid-b', 'customer_name' => 'Second']);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $first->id)
            ->call('loadOlderThreadHistory')
            ->assertSet('threadHistoryExpanded', true)
            ->call('selectConversation', $second->id)
            ->assertSet('threadHistoryExpanded', false);
    }
}
