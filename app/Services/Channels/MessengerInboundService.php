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
            $messaging = $entry['messaging'] ?? [];
            if (! is_array($messaging)) {
                continue;
            }

            foreach ($messaging as $event) {
                if (! is_array($event)) {
                    continue;
                }

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

        [$mediaUrl, $mediaMime] = $this->extractAttachment($message);

        if (($text === null || $text === '') && $mediaUrl === null) {
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

        $this->conversations->storeMessage($conversation, [
            'external_message_id' => $mid,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => $text !== '' ? $text : null,
            'media_url' => $mediaUrl,
            'media_mime' => $mediaMime,
            'raw_payload' => $event,
            'sent_at' => $sentAt,
        ]);

        $this->drafts->syncDraftFromConversation($conversation->fresh(['messages']));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0:?string,1:?string}
     */
    private function extractAttachment(array $message): array
    {
        $attachments = $message['attachments'] ?? [];
        if (! is_array($attachments)) {
            return [null, null];
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $type = (string) ($attachment['type'] ?? '');
            if (! in_array($type, ['image', 'file'], true)) {
                continue;
            }

            $url = data_get($attachment, 'payload.url');
            if (! is_string($url) || $url === '') {
                continue;
            }

            $mime = $type === 'image' ? 'image/jpeg' : null;

            // Prefer a durable copy when page token can fetch Graph attachments later;
            // for now store the CDN URL Meta provides (time-limited but enough for parse).
            return [$url, $mime];
        }

        return [null, null];
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
