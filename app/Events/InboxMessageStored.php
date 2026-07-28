<?php

namespace App\Events;

use App\Models\ChannelMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboxMessageStored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public string $direction,
        public string $channel,
    ) {}

    public static function fromMessage(ChannelMessage $message, string $channel): self
    {
        return new self(
            conversationId: (int) $message->channel_conversation_id,
            messageId: (int) $message->id,
            direction: (string) $message->direction,
            channel: $channel,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.inbox'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'InboxMessageStored';
    }

    /**
     * @return array{conversation_id: int, message_id: int, direction: string, channel: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'direction' => $this->direction,
            'channel' => $this->channel,
        ];
    }
}
