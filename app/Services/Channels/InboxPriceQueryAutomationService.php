<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\InboxPricedImageSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxPriceQueryAutomationService
{
    public const PHOTO_REQUEST_BODY = 'প্রডাক্টের ছবি দিবেন প্লিজ';

    public const LOW_MATCH_WAIT_BODY = 'এই প্রডাক্টের দাম জানানোর জন্য কিছুক্ষন সময় দিবেন কাইন্ডলি';

    public const MATCH_MIN_PERCENT = 80.0;

    public const RAW_PHOTO_REQUEST = InboxPricePhotoRequestService::RAW_FLAG;

    public const RAW_PRICED_IMAGE = 'auto_priced_image';

    public const RAW_LOW_MATCH_WAIT = 'auto_low_match_wait';

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduledPriceAsks = [];

    /**
     * Photo message ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduledPhotos = [];

    private static bool $terminatingRegistered = false;

    public function __construct(
        private InboxAutomationSettings $settings,
        private InboxQuickReplyMatcher $matcher,
        private InboxPricePhotoRequestService $photoRequests,
        private ChannelMessageImageMatchService $matches,
        private InboxPricedImageSender $pricedImages,
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

        $settings = app(InboxAutomationSettings::class);
        if (! $settings->anyEnabled()) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (! app(self::class)->isPriceAsk($message)) {
            return;
        }

        $conversationId = (int) $conversation->id;
        $messageId = (int) $message->id;
        if ($conversationId <= 0 || $messageId <= 0 || isset(self::$scheduledPriceAsks[$conversationId])) {
            return;
        }

        self::resetScheduledOnTerminate();
        self::$scheduledPriceAsks[$conversationId] = true;

        dispatch(function () use ($conversationId, $messageId): void {
            try {
                app(self::class)->handlePriceAskFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox price-query automation failed.', [
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

        $settings = app(InboxAutomationSettings::class);
        if (! $settings->enabled(InboxAutomationSettings::PRICED_IMAGE)
            && ! $settings->enabled(InboxAutomationSettings::LOW_MATCH_WAIT)) {
            return;
        }

        $photoId = (int) $photo->id;
        if ($photoId <= 0 || isset(self::$scheduledPhotos[$photoId])) {
            return;
        }

        self::resetScheduledOnTerminate();
        self::$scheduledPhotos[$photoId] = true;

        dispatch(function () use ($photoId): void {
            try {
                app(self::class)->handleMatchedPhotoFromId($photoId);
            } catch (Throwable $e) {
                Log::warning('Inbox price-query photo automation failed.', [
                    'message_id' => $photoId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function handlePriceAskFromIds(int $conversationId, int $messageId): void
    {
        $conversation = ChannelConversation::query()->with('messages')->find($conversationId);
        $message = ChannelMessage::query()->find($messageId);
        if (! $conversation || ! $message) {
            return;
        }

        $this->handlePriceAsk($conversation, $message);
    }

    public function handleMatchedPhotoFromId(int $photoId): void
    {
        $photo = ChannelMessage::query()->find($photoId);
        if (! $photo) {
            return;
        }

        $conversation = ChannelConversation::query()
            ->with('messages')
            ->find((int) $photo->channel_conversation_id);
        if (! $conversation) {
            return;
        }

        $this->handleMatchedPhoto($conversation, $photo);
    }

    public function handlePriceAsk(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if (! $this->settings->anyEnabled()) {
            return;
        }

        if (! $this->isPriceAsk($message)) {
            return;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return;
        }

        $conversation->loadMissing('messages');
        $photos = $this->sessionProductImages($conversation);

        if ($photos === []) {
            if (! $this->settings->enabled(InboxAutomationSettings::PHOTO_REQUEST)) {
                return;
            }

            $this->photoRequests->requestProductPhoto($conversation, $message);

            return;
        }

        foreach ($photos as $photo) {
            $this->handleMatchedPhoto($conversation, $photo);
        }
    }

    public function handleMatchedPhoto(ChannelConversation $conversation, ChannelMessage $photo): ?ChannelMessage
    {
        $conversation->loadMissing('messages');
        $photo->refresh();

        if (! $this->isSessionProductImage($conversation, $photo)) {
            return null;
        }

        if (! $this->sessionHasPriceAsk($conversation)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        if ($this->alreadyAutoRepliedTo($conversation, $photo)) {
            return null;
        }

        if (! $this->matches->hasFinishedMatching($photo)) {
            return null;
        }

        $percent = $this->matches->topMatchPercent($photo);
        $productId = $this->matches->topMatchProductId($photo);

        if ($percent >= self::MATCH_MIN_PERCENT && $productId > 0) {
            if (! $this->settings->enabled(InboxAutomationSettings::PRICED_IMAGE)) {
                return null;
            }

            return $this->sendPricedImage($conversation, $photo, $productId);
        }

        if (! $this->settings->enabled(InboxAutomationSettings::LOW_MATCH_WAIT)) {
            return null;
        }

        return $this->sendWaitText($conversation, $photo);
    }

    public function isPriceAsk(ChannelMessage $message): bool
    {
        return $this->matcher->isWhichProductPriceAsk($message);
    }

    /**
     * @return list<ChannelMessage>
     */
    public function sessionProductImages(ChannelConversation $conversation): array
    {
        $cutoff = $this->sessionCutoff();
        $out = [];

        foreach ($conversation->messages as $message) {
            if (! $this->matcher->isInboundProductImage($message)) {
                continue;
            }

            if (! $this->isInSession($message, $cutoff)) {
                continue;
            }

            $out[] = $message;
        }

        return $out;
    }

    public function sessionHasPriceAsk(ChannelConversation $conversation): bool
    {
        $cutoff = $this->sessionCutoff();

        foreach ($conversation->messages as $message) {
            if (! $this->isInSession($message, $cutoff)) {
                continue;
            }

            if ($this->isPriceAsk($message)) {
                return true;
            }
        }

        return false;
    }

    private function isSessionProductImage(ChannelConversation $conversation, ChannelMessage $photo): bool
    {
        if ((int) $photo->channel_conversation_id !== (int) $conversation->id) {
            return false;
        }

        if (! $this->matcher->isInboundProductImage($photo)) {
            return false;
        }

        return $this->isInSession($photo, $this->sessionCutoff());
    }

    private function sendPricedImage(ChannelConversation $conversation, ChannelMessage $photo, int $productId): ?ChannelMessage
    {
        $product = Product::query()->published()->whereKey($productId)->first();
        if (! $product) {
            return null;
        }

        $result = $this->pricedImages->send($conversation, $product, $photo);
        $stored = $result['message'] ?? null;
        if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
            return null;
        }

        return $this->flagOutbound($stored, self::RAW_PRICED_IMAGE);
    }

    private function sendWaitText(ChannelConversation $conversation, ChannelMessage $photo): ?ChannelMessage
    {
        $result = $this->replies->sendText(
            $conversation,
            self::LOW_MATCH_WAIT_BODY,
            false,
            $photo,
        );

        $stored = $result['message'] ?? null;
        if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
            return null;
        }

        return $this->flagOutbound($stored, self::RAW_LOW_MATCH_WAIT);
    }

    private function flagOutbound(ChannelMessage $stored, string $flag): ChannelMessage
    {
        $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
        $payload[$flag] = true;
        $stored->forceFill(['raw_payload' => $payload])->save();

        return $stored->fresh() ?? $stored;
    }

    private function alreadyAutoRepliedTo(ChannelConversation $conversation, ChannelMessage $inbound): bool
    {
        $inboundId = (int) $inbound->id;

        foreach ($conversation->messages as $row) {
            if ($row->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
                continue;
            }

            if ((int) ($row->reply_to_message_id ?? 0) !== $inboundId) {
                continue;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            if (($payload[self::RAW_PRICED_IMAGE] ?? false) === true
                || ($payload[self::RAW_LOW_MATCH_WAIT] ?? false) === true
                || ($payload[self::RAW_PHOTO_REQUEST] ?? false) === true) {
                return true;
            }

            return true;
        }

        return false;
    }

    private function sessionCutoff(): Carbon
    {
        $hours = max(1, (int) config('channels.inbox.thread_lookback_hours', 24));

        return now()->subHours($hours);
    }

    private function isInSession(ChannelMessage $message, Carbon $cutoff): bool
    {
        if ($message->sent_at === null) {
            return true;
        }

        return $message->sent_at->greaterThanOrEqualTo($cutoff);
    }

    private static function resetScheduledOnTerminate(): void
    {
        if (self::$terminatingRegistered) {
            return;
        }

        self::$terminatingRegistered = true;
        app()->terminating(static function (): void {
            self::$scheduledPriceAsks = [];
            self::$scheduledPhotos = [];
            self::$terminatingRegistered = false;
        });
    }
}
