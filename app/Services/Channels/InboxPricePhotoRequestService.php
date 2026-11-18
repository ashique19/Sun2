<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxPricePhotoRequestService
{
    public const RAW_FLAG = 'auto_photo_request';

    public const DEDUPE_HOURS = 12;

    public const FALLBACK_BODY = InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY;

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private InboxQuickReplyMatcher $matcher,
        private ChannelReplyService $replies,
    ) {}

    /**
     * After the webhook response, ask for a product photo on a price query
     * that has no inbound catalog photos. Never quotes a price.
     */
    public static function scheduleAfterInbound(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::PHOTO_REQUEST)) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text === '' || ! app(InboxQuickReplyMatcher::class)->looksLikePriceQuery($text)) {
            return;
        }

        $conversationId = (int) $conversation->id;
        $messageId = (int) $message->id;
        if ($conversationId <= 0 || $messageId <= 0 || isset(self::$scheduled[$conversationId])) {
            return;
        }

        if (self::$scheduled === []) {
            app()->terminating(static function (): void {
                self::$scheduled = [];
            });
        }

        self::$scheduled[$conversationId] = true;

        dispatch(function () use ($conversationId, $messageId): void {
            try {
                app(self::class)->requestFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox photo request failed.', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function requestFromIds(int $conversationId, int $messageId): ?ChannelMessage
    {
        $conversation = ChannelConversation::query()->with('messages')->find($conversationId);
        $message = ChannelMessage::query()->find($messageId);
        if (! $conversation || ! $message) {
            return null;
        }

        return $this->requestProductPhoto($conversation, $message);
    }

    public function requestProductPhoto(ChannelConversation $conversation, ChannelMessage $message): ?ChannelMessage
    {
        $conversation->loadMissing('messages');

        if (! $this->shouldRequestProductPhoto($conversation, $message)) {
            return null;
        }

        $result = $this->replies->sendText(
            $conversation,
            $this->photoRequestBody(),
            false,
            $message,
        );

        $stored = $result['message'] ?? null;
        if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
            return null;
        }

        $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
        $payload[self::RAW_FLAG] = true;
        $stored->forceFill(['raw_payload' => $payload])->save();

        return $stored->fresh();
    }

    public function shouldRequestProductPhoto(ChannelConversation $conversation, ChannelMessage $message): bool
    {
        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::PHOTO_REQUEST)) {
            return false;
        }

        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        if ((int) $message->channel_conversation_id !== (int) $conversation->id) {
            return false;
        }

        if (! $this->matcher->isWhichProductPriceAsk($message)) {
            return false;
        }

        $conversation->loadMissing('messages');

        if ($this->hasSessionProductImages($conversation)) {
            return false;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return false;
        }

        if ($this->hasOutboundAfter($conversation, $message)) {
            return false;
        }

        return ! $this->recentlyRequestedPhoto($conversation);
    }

    public function photoRequestBody(): string
    {
        return self::FALLBACK_BODY;
    }

    private function hasOutboundAfter(ChannelConversation $conversation, ChannelMessage $inbound): bool
    {
        $inboundId = (int) $inbound->id;
        $inboundAt = $inbound->sent_at;

        foreach ($conversation->messages as $row) {
            if ($row->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
                continue;
            }

            if ((int) ($row->reply_to_message_id ?? 0) === $inboundId) {
                return true;
            }

            if ($inboundAt === null || $row->sent_at === null) {
                if ((int) $row->id > $inboundId) {
                    return true;
                }

                continue;
            }

            if ($row->sent_at->greaterThan($inboundAt)) {
                return true;
            }

            if ($row->sent_at->equalTo($inboundAt) && (int) $row->id > $inboundId) {
                return true;
            }
        }

        return false;
    }

    private function recentlyRequestedPhoto(ChannelConversation $conversation): bool
    {
        $cutoff = now()->subHours(self::DEDUPE_HOURS);

        foreach ($conversation->messages as $row) {
            if ($row->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
                continue;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            if (($payload[self::RAW_FLAG] ?? false) !== true) {
                continue;
            }

            if ($row->sent_at === null || $row->sent_at->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return false;
    }

    private function hasSessionProductImages(ChannelConversation $conversation): bool
    {
        $hours = max(1, (int) config('channels.inbox.thread_lookback_hours', 24));
        $cutoff = now()->subHours($hours);

        foreach ($conversation->messages as $message) {
            if (! $this->matcher->isInboundProductImage($message)) {
                continue;
            }

            if ($message->sent_at === null || $message->sent_at->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return false;
    }
}
