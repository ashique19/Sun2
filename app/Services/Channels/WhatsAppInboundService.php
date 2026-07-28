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
                if (! is_array($change)) {
                    continue;
                }

                $field = (string) ($change['field'] ?? '');
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                try {
                    match ($field) {
                        'messages' => $this->handleValue($value),
                        'smb_message_echoes' => $this->handleSmbMessageEchoes($value),
                        'history' => $this->handleHistory($value),
                        default => null,
                    };
                } catch (Throwable $e) {
                    Log::error('WhatsApp inbound change failed.', [
                        'field' => $field,
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

        $this->ingestMessages($value, $messages, ChannelMessage::DIRECTION_INBOUND);
    }

    /**
     * Coexistence / WhatsApp Business app echoes (outbound from the phone app).
     *
     * @param  array<string, mixed>  $value
     */
    private function handleSmbMessageEchoes(array $value): void
    {
        $messages = $value['message_echoes'] ?? $value['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            return;
        }

        $this->ingestMessages($value, $messages, ChannelMessage::DIRECTION_OUTBOUND);
    }

    /**
     * Onboarding history chunks (when Meta shares chat history).
     *
     * @param  array<string, mixed>  $value
     */
    private function handleHistory(array $value): void
    {
        $history = $value['history'] ?? [];
        if (! is_array($history)) {
            return;
        }

        $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
        $displayPhone = data_get($value, 'metadata.display_phone_number');

        foreach ($history as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $threads = $chunk['threads'] ?? [];
            if (! is_array($threads)) {
                continue;
            }

            foreach ($threads as $thread) {
                if (! is_array($thread)) {
                    continue;
                }

                $customerPhone = (string) ($thread['id'] ?? '');
                $messages = $thread['messages'] ?? [];
                if ($customerPhone === '' || ! is_array($messages) || $messages === []) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $from = (string) ($message['from'] ?? '');
                    $to = (string) ($message['to'] ?? '');
                    $direction = $from !== '' && $from === $customerPhone
                        ? ChannelMessage::DIRECTION_INBOUND
                        : ChannelMessage::DIRECTION_OUTBOUND;

                    $this->storeParsedMessage(
                        $message,
                        $customerPhone,
                        $phoneNumberId,
                        is_string($displayPhone) ? $displayPhone : null,
                        null,
                        $direction,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<mixed>  $messages
     */
    private function ingestMessages(array $value, array $messages, string $defaultDirection): void
    {
        $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
        $displayPhone = data_get($value, 'metadata.display_phone_number');
        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
        $contactName = (string) data_get($contacts, '0.profile.name', '');

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $from = (string) ($message['from'] ?? '');
            $to = (string) ($message['to'] ?? '');
            $customerPhone = $defaultDirection === ChannelMessage::DIRECTION_INBOUND
                ? $from
                : ($to !== '' ? $to : $from);

            if ($customerPhone === '') {
                continue;
            }

            $this->storeParsedMessage(
                $message,
                $customerPhone,
                $phoneNumberId,
                is_string($displayPhone) ? $displayPhone : null,
                $contactName !== '' ? $contactName : null,
                $defaultDirection,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeParsedMessage(
        array $message,
        string $customerPhone,
        string $phoneNumberId,
        ?string $displayPhone,
        ?string $contactName,
        string $direction,
    ): void {
        $externalId = isset($message['id']) ? (string) $message['id'] : null;
        $timestamp = isset($message['timestamp']) ? (int) $message['timestamp'] : null;
        $sentAt = $timestamp ? Carbon::createFromTimestamp($timestamp) : now();
        $type = (string) ($message['type'] ?? 'text');

        [$body, $mediaUrl, $mediaMime] = $this->extractContent($message, $type);

        if ($body === null && $mediaUrl === null) {
            return;
        }

        $conversation = $this->conversations->findOrCreate(
            ChannelConversation::CHANNEL_WHATSAPP,
            $customerPhone,
            [
                'external_account_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'customer_name' => $contactName,
                'customer_phone' => $customerPhone,
                'meta' => [
                    'phone_number_id' => $phoneNumberId,
                    'display_phone_number' => $displayPhone,
                ],
            ],
        );

        $this->conversations->storeMessage($conversation, [
            'external_message_id' => $externalId,
            'direction' => $direction,
            'body' => $body,
            'media_url' => $mediaUrl,
            'media_mime' => $mediaMime,
            'raw_payload' => $message,
            'sent_at' => $sentAt,
        ]);

        if ($direction === ChannelMessage::DIRECTION_INBOUND) {
            $this->drafts->syncDraftFromConversation($conversation->fresh(['messages']));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function extractContent(array $message, string $type): array
    {
        $body = null;
        $mediaUrl = null;
        $mediaMime = null;

        if ($type === 'text') {
            $body = trim((string) data_get($message, 'text.body', ''));
            $body = $body !== '' ? $body : null;
        } elseif ($type === 'button') {
            $body = trim((string) data_get($message, 'button.text', data_get($message, 'button.payload', '')));
            $body = $body !== '' ? $body : null;
        } elseif ($type === 'interactive') {
            $body = trim((string) (
                data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title')
                ?? data_get($message, 'interactive.button_reply.id')
                ?? data_get($message, 'interactive.list_reply.id')
                ?? ''
            ));
            $body = $body !== '' ? $body : null;
        } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            $mediaId = (string) data_get($message, $type.'.id', '');
            $caption = trim((string) data_get($message, $type.'.caption', ''));
            $body = $caption !== '' ? $caption : ($type === 'sticker' ? '[Sticker]' : null);
            $mediaMime = data_get($message, $type.'.mime_type');
            $mediaMime = is_string($mediaMime) ? $mediaMime : null;
            if ($mediaId !== '') {
                $mediaUrl = $this->resolveMediaUrl($mediaId);
            }
            if ($body === null && $mediaUrl !== null && in_array($type, ['audio', 'video', 'document'], true)) {
                $body = '['.ucfirst($type).']';
            }
        } elseif ($type === 'location') {
            $name = trim((string) data_get($message, 'location.name', ''));
            $address = trim((string) data_get($message, 'location.address', ''));
            $lat = data_get($message, 'location.latitude');
            $lng = data_get($message, 'location.longitude');
            $parts = array_filter([$name, $address, ($lat !== null && $lng !== null ? $lat.', '.$lng : null)]);
            $body = $parts !== [] ? implode(' — ', $parts) : null;
        }

        return [$body, $mediaUrl, $mediaMime];
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
