<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppInboundService
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
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
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

            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                try {
                    $this->handleValue($value);
                } catch (Throwable $e) {
                    Log::error('WhatsApp inbound change failed.', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleValue(array $value): void
    {
        $messages = $value['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            return;
        }

        $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
        $contactName = (string) data_get($contacts, '0.profile.name', '');

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $from = (string) ($message['from'] ?? '');
            if ($from === '') {
                continue;
            }

            $externalId = isset($message['id']) ? (string) $message['id'] : null;
            $timestamp = isset($message['timestamp']) ? (int) $message['timestamp'] : null;
            $sentAt = $timestamp ? Carbon::createFromTimestamp($timestamp) : now();
            $type = (string) ($message['type'] ?? 'text');

            $body = null;
            $mediaUrl = null;
            $mediaMime = null;

            if ($type === 'text') {
                $body = trim((string) data_get($message, 'text.body', ''));
                if ($body === '') {
                    $body = null;
                }
            } elseif (in_array($type, ['image', 'document'], true)) {
                $mediaId = (string) data_get($message, $type.'.id', '');
                $caption = trim((string) data_get($message, $type.'.caption', ''));
                $body = $caption !== '' ? $caption : null;
                $mediaMime = data_get($message, $type.'.mime_type');
                $mediaMime = is_string($mediaMime) ? $mediaMime : null;
                if ($mediaId !== '') {
                    $mediaUrl = $this->resolveMediaUrl($mediaId);
                }
            } else {
                continue;
            }

            if ($body === null && $mediaUrl === null) {
                continue;
            }

            $conversation = $this->conversations->findOrCreate(
                ChannelConversation::CHANNEL_WHATSAPP,
                $from,
                [
                    'external_account_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                    'customer_name' => $contactName !== '' ? $contactName : null,
                    'customer_phone' => $from,
                    'meta' => [
                        'phone_number_id' => $phoneNumberId,
                        'display_phone_number' => data_get($value, 'metadata.display_phone_number'),
                    ],
                ],
            );

            $this->conversations->storeMessage($conversation, [
                'external_message_id' => $externalId,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'body' => $body,
                'media_url' => $mediaUrl,
                'media_mime' => $mediaMime,
                'raw_payload' => $message,
                'sent_at' => $sentAt,
            ]);

            $this->drafts->syncDraftFromConversation($conversation->fresh(['messages']));
        }
    }

    private function resolveMediaUrl(string $mediaId): ?string
    {
        $token = (string) config('whatsapp.access_token', '');
        if ($token === '') {
            return null;
        }

        $version = (string) config('whatsapp.graph_version', config('facebook.graph_version', 'v25.0'));

        try {
            $meta = Http::timeout(15)
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$mediaId);

            if (! $meta->successful()) {
                return null;
            }

            $url = (string) $meta->json('url', '');

            return $url !== '' ? $url : null;
        } catch (Throwable $e) {
            Log::warning('WhatsApp media URL resolve failed.', [
                'media_id' => $mediaId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
