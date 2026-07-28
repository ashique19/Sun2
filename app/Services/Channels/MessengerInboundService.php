<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessengerInboundService
{
    public function __construct(
        private ChannelConversationService $conversations,
        private ChannelOrderDraftService $drafts,
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

        // Ignore echo / delivery / read noise.
        if (! empty($message['is_echo'])) {
            return;
        }

        $senderId = (string) data_get($event, 'sender.id', '');
        if ($senderId === '') {
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

        $conversation = $this->conversations->findOrCreate(
            ChannelConversation::CHANNEL_MESSENGER,
            $senderId,
            [
                'external_account_id' => $pageId,
                'meta' => ['page_id' => $pageId],
            ],
        );

        if ($attachments === []) {
            $this->conversations->storeMessage($conversation, [
                'external_message_id' => $mid,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
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
                    'direction' => ChannelMessage::DIRECTION_INBOUND,
                    // Caption/text only on the first image of an album.
                    'body' => $index === 0 && $text !== null && $text !== '' ? $text : null,
                    'media_url' => $attachment['url'],
                    'media_mime' => $attachment['mime'],
                    'raw_payload' => $event,
                    'sent_at' => $sentAt,
                ]);
            }
        }

        $this->drafts->syncDraftFromConversation($conversation->fresh(['messages']));
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
        $token = (string) config('facebook.messenger.page_access_token', '');
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
