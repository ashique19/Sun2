<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ChannelReplyService
{
    public function __construct(
        private ChannelConversationService $conversations,
    ) {}

    /**
     * Send a free-form text reply within the 24h customer care window.
     *
     * @return array{ok:bool,message:?ChannelMessage,error:?string,outside_window:bool}
     */
    public function sendText(ChannelConversation $conversation, string $text, bool $force = false): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'Reply text is empty.',
                'outside_window' => false,
            ];
        }

        if (! $force && ! $conversation->isWithinMessagingWindow()) {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'Outside the 24-hour messaging window. Customer must message first.',
                'outside_window' => true,
            ];
        }

        try {
            $externalId = match ($conversation->channel) {
                ChannelConversation::CHANNEL_MESSENGER => $this->sendMessenger($conversation, $text),
                ChannelConversation::CHANNEL_WHATSAPP => $this->sendWhatsApp($conversation, $text),
                default => throw new RuntimeException('Unsupported channel: '.$conversation->channel),
            };

            $stored = $this->conversations->storeMessage($conversation, [
                'external_message_id' => $externalId,
                'direction' => ChannelMessage::DIRECTION_OUTBOUND,
                'body' => $text,
                'sent_at' => now(),
                'raw_payload' => ['text' => $text],
            ]);

            return [
                'ok' => true,
                'message' => $stored,
                'error' => null,
                'outside_window' => false,
            ];
        } catch (Throwable $e) {
            Log::warning('Channel reply failed.', [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => null,
                'error' => $e->getMessage(),
                'outside_window' => false,
            ];
        }
    }

    private function sendMessenger(ChannelConversation $conversation, string $text): ?string
    {
        $token = (string) config('facebook.messenger.page_access_token', '');
        if ($token === '') {
            throw new RuntimeException('FACEBOOK_PAGE_ACCESS_TOKEN is not configured.');
        }

        $version = (string) config('facebook.graph_version', 'v25.0');
        $url = 'https://graph.facebook.com/'.$version.'/me/messages';

        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'recipient' => ['id' => $conversation->external_user_id],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Messenger Send API error ('.$response->status().'): '.$response->body());
        }

        $mid = $response->json('message_id');

        return is_string($mid) && $mid !== '' ? $mid : null;
    }

    private function sendWhatsApp(ChannelConversation $conversation, string $text): ?string
    {
        $token = (string) config('whatsapp.access_token', '');
        $phoneNumberId = (string) (
            $conversation->external_account_id
            ?: config('whatsapp.phone_number_id', '')
        );

        if ($token === '' || $phoneNumberId === '') {
            throw new RuntimeException('WhatsApp access token or phone number id is not configured.');
        }

        $version = (string) config('whatsapp.graph_version', config('facebook.graph_version', 'v25.0'));
        $url = 'https://graph.facebook.com/'.$version.'/'.$phoneNumberId.'/messages';

        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $conversation->external_user_id,
                'type' => 'text',
                'text' => ['body' => $text],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp Send API error ('.$response->status().'): '.$response->body());
        }

        $id = data_get($response->json(), 'messages.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
