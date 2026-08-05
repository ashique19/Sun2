<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessengerInboundService
{
    public function __construct(
        private ChannelConversationService $conversations,
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhookPayload(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }

        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = isset($entry['id']) ? (string) $entry['id'] : null;

            // When Page Inbox (or another app) is the primary receiver, Meta delivers
            // customer messages under entry.standby instead of entry.messaging.
            $events = array_merge(
                $this->eventList($entry['messaging'] ?? null),
                $this->eventList($entry['standby'] ?? null),
            );

            foreach ($events as $event) {
                try {
                    $this->handleMessagingEvent($event, $pageId);
                } catch (Throwable $e) {
                    Log::error('Messenger inbound event failed.', [
                        'message' => $e->getMessage(),
                        'mid' => data_get($event, 'message.mid'),
                    ]);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventList(mixed $events): array
    {
        if (! is_array($events)) {
            return [];
        }

        $normalized = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $normalized[] = $event;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleMessagingEvent(array $event, ?string $pageId): void
    {
        $message = $event['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $isEcho = ! empty($message['is_echo']);
        $senderId = (string) data_get($event, 'sender.id', '');
        $recipientId = (string) data_get($event, 'recipient.id', '');

        // Echoes are Page→user replies (e.g. answered in Facebook Page Inbox).
        // sender = page, recipient = customer PSID.
        if ($isEcho) {
            $messagingUserId = $recipientId !== '' ? $recipientId : '';
            $direction = ChannelMessage::DIRECTION_OUTBOUND;
        } else {
            $messagingUserId = $senderId;
            $direction = ChannelMessage::DIRECTION_INBOUND;
        }

        if ($messagingUserId === '') {
            return;
        }

        // Never treat the Page itself as the conversation peer.
        if ($pageId !== null && $messagingUserId === $pageId) {
            return;
        }

        $mid = isset($message['mid']) ? (string) $message['mid'] : null;
        $text = isset($message['text']) ? trim((string) $message['text']) : null;
        $timestampMs = isset($event['timestamp']) ? (int) $event['timestamp'] : null;
        $sentAt = $timestampMs
            ? Carbon::createFromTimestampMs($timestampMs)
            : now();

        $attachments = $this->extractAttachments($message);

        if (($text === null || $text === '') && $attachments === []) {
            return;
        }

        $existingName = ChannelConversation::query()
            ->where('channel', ChannelConversation::CHANNEL_MESSENGER)
            ->where('external_user_id', $messagingUserId)
            ->value('customer_name');

        $customerName = is_string($existingName) && trim($existingName) !== ''
            ? trim($existingName)
            : $this->resolveCustomerName($messagingUserId);

        $conversation = $this->conversations->findOrCreate(
            ChannelConversation::CHANNEL_MESSENGER,
            $messagingUserId,
            array_filter([
                'external_account_id' => $pageId,
                'customer_name' => $customerName,
                'meta' => ['page_id' => $pageId],
            ], fn ($value) => $value !== null),
        );

        if ($customerName && $conversation->customer_name !== $customerName) {
            $conversation->forceFill(['customer_name' => $customerName])->save();
        }

        if ($attachments === []) {
            $this->conversations->storeMessage($conversation, [
                'external_message_id' => $mid,
                'direction' => $direction,
                'body' => $text !== '' ? $text : null,
                'media_url' => null,
                'media_mime' => null,
                'raw_payload' => $event,
                'sent_at' => $sentAt,
            ]);
        } else {
            $total = count($attachments);
            foreach ($attachments as $index => $attachment) {
                $this->conversations->storeMessage($conversation, [
                    'external_message_id' => $this->attachmentExternalId($mid, $index, $total),
                    'direction' => $direction,
                    // Caption/text only on the first image of an album.
                    'body' => $index === 0 && $text !== null && $text !== '' ? $text : null,
                    'media_url' => $attachment['url'],
                    'media_mime' => $attachment['mime'],
                    'raw_payload' => $event,
                    'sent_at' => $sentAt,
                ]);
            }
        }
    }

    private function resolveCustomerName(string $psid): ?string
    {
        $cacheKey = 'messenger.psid_name.'.$psid;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->tokens->token();
        if ($token === '') {
            return null;
        }

        try {
            $version = $this->tokens->graphVersion();
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$psid, [
                    'fields' => 'name,first_name,last_name',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $name = $response->json('name');
            if (is_string($name) && trim($name) !== '') {
                $resolved = trim($name);
                Cache::put($cacheKey, $resolved, now()->addDays(7));

                return $resolved;
            }

            $first = trim((string) ($response->json('first_name') ?? ''));
            $last = trim((string) ($response->json('last_name') ?? ''));
            $combined = trim($first.' '.$last);

            if ($combined !== '') {
                Cache::put($cacheKey, $combined, now()->addDays(7));

                return $combined;
            }
        } catch (Throwable $e) {
            Log::debug('Messenger PSID name lookup failed.', [
                'psid' => $psid,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array{url: string, mime: ?string}>
     */
    private function extractAttachments(array $message): array
    {
        $attachments = $message['attachments'] ?? [];
        if (! is_array($attachments)) {
            return [];
        }

        $extracted = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $type = (string) ($attachment['type'] ?? '');

            // Accept common media types that could contain product images
            // 'fallback' is used when Meta can't determine exact type but still provides a URL
            if (! in_array($type, ['image', 'file', 'audio', 'video', 'fallback'], true)) {
                continue;
            }

            $url = data_get($attachment, 'payload.url');
            if (! is_string($url) || $url === '') {
                continue;
            }

            $mime = data_get($attachment, 'payload.mime_type');
            if (! is_string($mime) || $mime === '') {
                $mime = match ($type) {
                    'image' => 'image/jpeg',
                    'audio' => 'audio/mpeg',
                    'video' => 'video/mp4',
                    default => null,
                };
            }

            $extracted[] = ['url' => $url, 'mime' => $mime];
        }

        return $extracted;
    }

    private function attachmentExternalId(?string $mid, int $index, int $total): ?string
    {
        if ($mid === null || $mid === '') {
            return null;
        }

        // Keep single-attachment mids stable for backward-compatible dedupe.
        if ($total === 1) {
            return $mid;
        }

        return $mid.'#'.$index;
    }

    /**
     * Resolve a Graph attachment URL with the page token when needed.
     */
    public function authorizedMediaUrl(string $url): string
    {
        $token = $this->tokens->token();
        if ($token === '' || ! str_contains($url, 'fbcdn') && ! str_contains($url, 'facebook.com')) {
            return $url;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->get($url);

            return $response->effectiveUri()?->__toString() ?: $url;
        } catch (Throwable) {
            return $url;
        }
    }
}
