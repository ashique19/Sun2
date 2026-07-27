<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Channels\ChannelReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('does not pull chat history from Facebook');
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
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::response(['recipient_id' => 'psid-1'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation(['last_read_at' => null]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('selectedConversationId', $conversation->id);

        $this->assertNotNull($conversation->fresh()->last_read_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/me/messages')
                && ($request['sender_action'] ?? null) === 'mark_seen'
                && ($request['recipient']['id'] ?? null) === 'psid-1';
        });
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

        $file = \Illuminate\Http\UploadedFile::fake()->image('reply.jpg', 40, 40);

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
}
