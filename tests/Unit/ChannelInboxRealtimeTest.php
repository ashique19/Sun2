<?php

namespace Tests\Unit;

use App\Events\InboxMessageStored;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\ChannelConversationService;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelInboxRealtimeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function store_message_broadcasts_when_realtime_enabled(): void
    {
        config(['channels.inbox.realtime_enabled' => true]);
        Event::fake([InboxMessageStored::class]);

        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U-RT-1');

        $message = $service->storeMessage($conversation, [
            'external_message_id' => 'mid-rt-1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Realtime hello',
            'sent_at' => now(),
        ]);

        Event::assertDispatched(InboxMessageStored::class, function (InboxMessageStored $event) use ($conversation, $message) {
            return $event->conversationId === $conversation->id
                && $event->messageId === $message->id
                && $event->direction === ChannelMessage::DIRECTION_INBOUND
                && $event->channel === ChannelConversation::CHANNEL_MESSENGER;
        });
    }

    #[Test]
    public function store_message_does_not_broadcast_on_dedupe_or_when_realtime_disabled(): void
    {
        config(['channels.inbox.realtime_enabled' => true]);
        Event::fake([InboxMessageStored::class]);

        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U-RT-2');

        $service->storeMessage($conversation, [
            'external_message_id' => 'mid-rt-2',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'first',
            'sent_at' => now(),
        ]);

        Event::assertDispatchedTimes(InboxMessageStored::class, 1);

        $service->storeMessage($conversation, [
            'external_message_id' => 'mid-rt-2',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'duplicate',
            'sent_at' => now(),
        ]);

        Event::assertDispatchedTimes(InboxMessageStored::class, 1);

        config(['channels.inbox.realtime_enabled' => false]);
        Event::fake([InboxMessageStored::class]);

        $service->storeMessage($conversation, [
            'external_message_id' => 'mid-rt-3',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'no broadcast',
            'sent_at' => now(),
        ]);

        Event::assertNotDispatched(InboxMessageStored::class);
    }

    #[Test]
    public function store_message_survives_when_reverb_broadcaster_cannot_boot(): void
    {
        config(['channels.inbox.realtime_enabled' => true]);

        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('event')->andThrow(new \RuntimeException(
            'Failed to create broadcaster for connection "reverb" with error: Class "Pusher\Pusher" not found.',
        ));
        $this->app->instance(Factory::class, $factory);

        $service = app(ChannelConversationService::class);
        $conversation = $service->findOrCreate(ChannelConversation::CHANNEL_MESSENGER, 'U-RT-3');

        $message = $service->storeMessage($conversation, [
            'external_message_id' => 'mid-rt-fail',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'still stored',
            'sent_at' => now(),
        ]);

        $this->assertNotNull($message->id);
        $this->assertSame('still stored', $message->fresh()->body);
        $this->assertSame(1, ChannelMessage::query()->where('external_message_id', 'mid-rt-fail')->count());
    }
}
