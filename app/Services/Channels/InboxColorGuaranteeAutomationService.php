<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxColorGuaranteeAutomationService
{
    public const REPLY_LABEL = 'কালার গ্যারান্টি';

    public const CATEGORY_NAME = 'পাহাড়ি আলছড়া';

    public const RAW_FLAG = 'auto_color_guarantee';

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduledAsks = [];

    /**
     * Photo message ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduledPhotos = [];

    public function __construct(
        private InboxAutomationSettings $settings,
        private InboxQuickReplyMatcher $matcher,
        private InboxQuickReplyService $quickReplies,
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

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::COLOR_GUARANTEE)) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (! app(InboxQuickReplyMatcher::class)->isColorGuaranteeAsk($message)) {
            return;
        }

        $conversationId = (int) $conversation->id;
        $messageId = (int) $message->id;
        if ($conversationId <= 0 || $messageId <= 0 || isset(self::$scheduledAsks[$conversationId])) {
            return;
        }

        self::$scheduledAsks[$conversationId] = true;

        dispatch(function () use ($conversationId, $messageId): void {
            try {
                app(self::class)->replyFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox color-guarantee automation failed.', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public static function scheduleAfterMatch(ChannelMessage $photo): void
    {
        if ($photo->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $photo->isImageAttachment()
            || $photo->isStickerAttachment()) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::COLOR_GUARANTEE)) {
            return;
        }

        $photoId = (int) $photo->id;
        if ($photoId <= 0 || isset(self::$scheduledPhotos[$photoId])) {
            return;
        }

        self::$scheduledPhotos[$photoId] = true;

        dispatch(function () use ($photoId): void {
            try {
                app(self::class)->replyAfterMatchedPhoto($photoId);
            } catch (Throwable $e) {
                Log::warning('Inbox color-guarantee photo automation failed.', [
                    'message_id' => $photoId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function replyFromIds(int $conversationId, int $messageId): ?ChannelMessage
    {
        $conversation = ChannelConversation::query()->with('messages')->find($conversationId);
        $message = ChannelMessage::query()->find($messageId);
        if (! $conversation || ! $message) {
            return null;
        }

        return $this->reply($conversation, $message);
    }

    public function replyAfterMatchedPhoto(int $photoId): ?ChannelMessage
    {
        $photo = ChannelMessage::query()->find($photoId);
        if (! $photo) {
            return null;
        }

        $conversation = ChannelConversation::query()
            ->with('messages')
            ->find((int) $photo->channel_conversation_id);
        if (! $conversation) {
            return null;
        }

        $ask = $this->sessionColorGuaranteeAsk($conversation);
        if ($ask === null) {
            return null;
        }

        return $this->reply($conversation, $ask);
    }

    public function reply(ChannelConversation $conversation, ChannelMessage $message): ?ChannelMessage
    {
        if (! $this->settings->enabled(InboxAutomationSettings::COLOR_GUARANTEE)) {
            return null;
        }

        if (! $this->matcher->isColorGuaranteeAsk($message)) {
            return null;
        }

        if (! $this->matcher->sessionHasPahariAlchoraProduct($conversation)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        $body = $this->replyBody();
        if ($body === null || $body === '') {
            return null;
        }

        return InboxPriceQueryAutomationService::withConversationLock(
            (int) $conversation->id,
            function () use ($conversation, $message, $body): ?ChannelMessage {
                if ($this->alreadyReplied($conversation, $message, $body)) {
                    return null;
                }

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

    public function replyBody(): ?string
    {
        $reply = $this->savedReply();

        return $reply['body'] ?? null;
    }

    /**
     * @return array{id: string, label: string, body: string}|null
     */
    public function savedReply(): ?array
    {
        foreach ($this->quickReplies->all() as $reply) {
            if ($this->matcher->isColorGuaranteeReply($reply)) {
                return $reply;
            }
        }

        return null;
    }

    private function sessionColorGuaranteeAsk(ChannelConversation $conversation): ?ChannelMessage
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $conversation->loadMissing('messages');

        foreach ($conversation->messages as $row) {
            if ($row->direction !== ChannelMessage::DIRECTION_INBOUND) {
                continue;
            }

            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }

            if ($this->matcher->isColorGuaranteeAsk($row)) {
                return $row;
            }
        }

        return null;
    }

    private function alreadyReplied(ChannelConversation $conversation, ChannelMessage $inbound, string $body): bool
    {
        $inboundId = (int) $inbound->id;
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get() as $row) {
            if ((int) ($row->reply_to_message_id ?? 0) === $inboundId) {
                return true;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            $isAuto = ($payload[self::RAW_FLAG] ?? false) === true
                || (string) $row->body === $body;
            if (! $isAuto) {
                continue;
            }

            if ($row->sent_at === null || $row->sent_at->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return false;
    }
}
