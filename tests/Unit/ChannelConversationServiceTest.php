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
}
