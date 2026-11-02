<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\ChannelConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_or_create_is_unique_per_channel_user(): void
    {
        $service = app(ChannelConversationService::class);

        $a = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U1', [
            'customer_name' => 'A',
        ]);
        $b = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U1', [
            'customer_name' => 'B',
        ]);
        $c = $service->findOrCreate(ChannelConversation::CHANNEL_WHATSAPP, 'U1');

        $this->assertSame($a->id, $b->id);
        $this->assertSame('B', $b->fresh()->customer_name);
        $this->assertNotSame($a->id, $c->id);
        $this->assertSame(2, ChannelConversation::query()->count());
    }

    public function test_store_message_is_idempotent_and_updates_inbound_timestamp(): void
    {
        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U2');

        $first = $service->storeMessage($conversation, [
            'external_message_id' => 'mid-1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hi',
            'sent_at' => now()->subMinutes(5),
        ]);
        $second = $service->storeMessage($conversation, [
            'external_message_id' => 'mid-1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hi again',
            'sent_at' => now(),
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ChannelMessage::query()->count());
        $this->assertNotNull($conversation->fresh()->last_inbound_at);
        $this->assertTrue($conversation->fresh()->isWithinMessagingWindow());
    }

    public function test_resolve_reply_to_id_matches_bare_and_album_mids(): void
    {
        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U-REPLY');

        $parent = $service->storeMessage($conversation, [
            'external_message_id' => 'm_parent',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'original',
            'sent_at' => now()->subMinute(),
        ]);
        $album = $service->storeMessage($conversation, [
            'external_message_id' => 'm_album#1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.com/a.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subSeconds(30),
        ]);

        $this->assertSame($parent->id, $service->resolveReplyToId($conversation, 'm_parent'));
        $this->assertSame($album->id, $service->resolveReplyToId($conversation, 'm_album'));
        $this->assertNull($service->resolveReplyToId($conversation, 'm_missing'));
        $this->assertNull($service->resolveReplyToId($conversation, null));
    }

    public function test_store_message_backfills_missing_reply_to(): void
    {
        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U-BACKFILL');

        $parent = $service->storeMessage($conversation, [
            'external_message_id' => 'm_orig',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hi',
            'sent_at' => now()->subMinute(),
        ]);
        $reply = $service->storeMessage($conversation, [
            'external_message_id' => 'm_reply',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'later',
            'sent_at' => now(),
        ]);

        $this->assertNull($reply->reply_to_message_id);

        $updated = $service->storeMessage($conversation, [
            'external_message_id' => 'm_reply',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'reply_to_message_id' => $parent->id,
        ]);

        $this->assertSame($reply->id, $updated->id);
        $this->assertSame($parent->id, $updated->fresh()->reply_to_message_id);
        $this->assertSame('later', $updated->fresh()->body);
    }
}
