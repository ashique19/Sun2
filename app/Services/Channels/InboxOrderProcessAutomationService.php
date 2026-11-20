<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxOrderProcessAutomationService
{
    public const REPLY_LABEL = 'অর্ডার প্রসেস';

    public const RAW_FLAG = 'auto_order_process';

    public const RAW_KIND = 'auto_order_process_kind';

    public const KIND_FALLBACK = 'fallback';

    public const BODY_FALLBACK = 'সময় দিয়ে সহযোগিতা করবেন প্লিজ, চেক করে জানাচ্ছি।';

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private InboxAutomationSettings $settings,
        private InboxQuickReplyMatcher $matcher,
        private InboxQuickReplyService $quickReplies,
        private ChannelReplyService $replies,
        private InboxDeliveryChargeAutomationService $delivery,
        private InboxAdvanceAutomationService $advance,
    ) {}

    public static function willHandle(ChannelMessage $message): bool
    {
        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::ORDER_PROCESS)) {
            return false;
        }

        $body = app(self::class)->replyBody();
        if ($body === null || $body === '') {
            return false;
        }

        return app(InboxQuickReplyMatcher::class)->isOrderProcessAsk($message);
    }

    public static function scheduleAfterInbound(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::ORDER_PROCESS)) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (! app(InboxQuickReplyMatcher::class)->isOrderProcessAsk($message)) {
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
                app(self::class)->replyFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox order-process automation failed.', [
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
        if (! $this->settings->enabled(InboxAutomationSettings::ORDER_PROCESS)) {
            return null;
        }

        if (! $this->matcher->isOrderProcessAsk($message)) {
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

                $this->sendCompanions($conversation, $message);

                return $stored->fresh();
            },
        );
    }

    public function replyBody(): ?string
    {
        $reply = $this->savedReply();

        return $reply['body'] ?? null;
    }

    private function sendCompanions(ChannelConversation $conversation, ChannelMessage $message): void
    {
        $deliveryPlan = $this->delivery->planAlongsideOrder($conversation, $message);
        $advancePlan = $this->advance->planAlongsideOrder($conversation, $message);

        if (
            is_array($advancePlan)
            && in_array($advancePlan['kind'], [
                InboxAdvanceAutomationService::KIND_LISTED,
                InboxAdvanceAutomationService::KIND_COD,
            ], true)
            && is_array($deliveryPlan)
            && $deliveryPlan['kind'] === InboxDeliveryChargeAutomationService::KIND_CHARGE
        ) {
            $deliveryPlan = null;
        }

        if ($this->companionsConflict($conversation, $message, $deliveryPlan, $advancePlan)) {
            $this->sendFallback($conversation, $message);

            return;
        }

        if (is_array($deliveryPlan)) {
            $this->delivery->sendAlongsideOrder($conversation, $message, $deliveryPlan);
        }

        if (is_array($advancePlan)) {
            $this->advance->sendAlongsideOrder($conversation, $message, $advancePlan);
        }
    }

    /**
     * @param  array{kind: string, body: string}|null  $deliveryPlan
     * @param  array{kind: string, body: string}|null  $advancePlan
     */
    private function companionsConflict(
        ChannelConversation $conversation,
        ChannelMessage $message,
        ?array $deliveryPlan,
        ?array $advancePlan,
    ): bool {
        $text = (string) ($message->body ?? '');
        $askedTime = $this->matcher->hasDeliveryTimeOrChargeCue($text);
        $askedAdvance = $this->matcher->looksLikeAdvance($text) || $this->matcher->looksLikeCodPush($text);
        $location = $this->delivery->locationFor($conversation, $message);
        $hasPlace = $location['zone'] !== null
            || (is_string($location['area']) && $location['area'] !== '')
            || $this->advance->listedAreaFromSession($conversation, $message) !== null;

        if ($askedTime && $askedAdvance && ! $hasPlace) {
            return true;
        }

        if ($deliveryPlan === null || $advancePlan === null) {
            return false;
        }

        $deliveryKind = $deliveryPlan['kind'];
        $advanceKind = $advancePlan['kind'];

        if ($deliveryKind === InboxDeliveryChargeAutomationService::KIND_KOBE_ASK
            && $advanceKind === InboxAdvanceAutomationService::KIND_ASK) {
            return true;
        }

        if ($deliveryKind === InboxDeliveryChargeAutomationService::KIND_CHARGE
            && $advanceKind === InboxAdvanceAutomationService::KIND_ASK) {
            return true;
        }

        return ($deliveryPlan['body'] ?? '') === InboxDeliveryChargeAutomationService::BODY;
    }

    private function sendFallback(ChannelConversation $conversation, ChannelMessage $message): void
    {
        $result = $this->replies->sendText(
            $conversation,
            self::BODY_FALLBACK,
            false,
            $message,
        );

        $stored = $result['message'] ?? null;
        if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
            return;
        }

        $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
        $payload[self::RAW_FLAG] = true;
        $payload[self::RAW_KIND] = self::KIND_FALLBACK;
        $stored->forceFill(['raw_payload' => $payload])->save();
    }

    /**
     * @return array{id: string, label: string, body: string}|null
     */
    public function savedReply(): ?array
    {
        foreach ($this->quickReplies->all() as $reply) {
            if ($this->matcher->isOrderProcessReply($reply)) {
                return $reply;
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
                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
                $own = ($payload[self::RAW_FLAG] ?? false) === true
                    || (string) $row->body === $body;
                if ($own) {
                    return true;
                }
                if (InboxAutomationSettings::isAutomatedOutbound($row)) {
                    continue;
                }

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
