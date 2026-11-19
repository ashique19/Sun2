<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxPriceNegotiateAutomationService
{
    public const BODY_1 = "আন্তরিকভাবে দুঃখিত, \nআমাদের প্রডাক্ট এর দাম ফিক্সড আপু। \nএগুলো অফারের রেট।";

    public const BODY_2 = 'আপনার কথা বুঝতে পেরেছি আপু। কিন্তু আমরা যে অফারের রেট দিচ্ছি, এর উপর কোন রকম ডিস্কাউন্ট/অফার সম্ভব নয়।';

    public const BODY_3 = 'দুঃখিত আপু।';

    public const RAW_FLAG = 'auto_price_negotiate';

    /**
     * Inbound message ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private InboxAutomationSettings $settings,
        private InboxQuickReplyMatcher $matcher,
        private ChannelReplyService $replies,
    ) {}

    public static function scheduleAfterInbound(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::PRICE_NEGOTIATE)) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (! app(InboxQuickReplyMatcher::class)->isPriceNegotiateAsk($message)) {
            return;
        }

        $conversationId = (int) $conversation->id;
        $messageId = (int) $message->id;
        if ($conversationId <= 0 || $messageId <= 0 || isset(self::$scheduled[$messageId])) {
            return;
        }

        if (self::$scheduled === []) {
            app()->terminating(static function (): void {
                self::$scheduled = [];
            });
        }

        self::$scheduled[$messageId] = true;

        dispatch(function () use ($conversationId, $messageId): void {
            try {
                app(self::class)->replyFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox price-negotiate automation failed.', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function replyFromIds(int $conversationId, int $messageId): ?ChannelMessage
    {
        $conversation = ChannelConversation::query()->find($conversationId);
        $message = ChannelMessage::query()->find($messageId);
        if (! $conversation || ! $message) {
            return null;
        }

        return $this->reply($conversation, $message);
    }

    public function reply(ChannelConversation $conversation, ChannelMessage $message): ?ChannelMessage
    {
        if (! $this->settings->enabled(InboxAutomationSettings::PRICE_NEGOTIATE)) {
            return null;
        }

        if (! $this->matcher->isPriceNegotiateAsk($message)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        return InboxPriceQueryAutomationService::withConversationLock(
            (int) $conversation->id,
            function () use ($conversation, $message): ?ChannelMessage {
                if ($this->alreadyReplied($conversation, $message)) {
                    return null;
                }

                $body = $this->bodyForSession($conversation);
                $result = $this->replies->sendText(
                    $conversation,
                    $body,
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
            },
        );
    }

    private function bodyForSession(ChannelConversation $conversation): string
    {
        $count = $this->sessionReplyCount($conversation);

        return match (true) {
            $count <= 0 => self::BODY_1,
            $count === 1 => self::BODY_2,
            default => self::BODY_3,
        };
    }

    private function sessionReplyCount(ChannelConversation $conversation): int
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $count = 0;

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get() as $row) {
            if (! $this->isAutoNegotiate($row)) {
                continue;
            }

            if ($row->sent_at === null || $row->sent_at->greaterThanOrEqualTo($cutoff)) {
                $count++;
            }
        }

        return $count;
    }

    private function isAutoNegotiate(ChannelMessage $row): bool
    {
        $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
        if (($payload[self::RAW_FLAG] ?? false) === true) {
            return true;
        }

        $body = (string) $row->body;

        return $body === self::BODY_1 || $body === self::BODY_2 || $body === self::BODY_3;
    }

    private function alreadyReplied(ChannelConversation $conversation, ChannelMessage $inbound): bool
    {
        $inboundId = (int) $inbound->id;

        return ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $inboundId)
            ->exists();
    }
}
