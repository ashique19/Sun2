<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Throwable;

class WhatsAppCloudApiService
{
    /**
     * Probe the configured Cloud API credentials (token + phone number id).
     *
     * WhatsApp Cloud API does not expose Messenger-style conversation history
     * sync — inbound traffic arrives only via webhooks. This probe confirms
     * the send/media token works so staff know credentials are valid.
     *
     * @return array{ok: bool, message: string, display_phone_number: ?string, verified_name: ?string}
     */
    public function probe(): array
    {
        $token = trim((string) config('whatsapp.access_token', ''));
        $phoneNumberId = trim((string) config('whatsapp.phone_number_id', ''));
        $version = (string) config('whatsapp.graph_version', config('facebook.graph_version', 'v25.0'));

        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'WHATSAPP_ACCESS_TOKEN is not configured.',
                'display_phone_number' => null,
                'verified_name' => null,
            ];
        }

        if ($phoneNumberId === '') {
            return [
                'ok' => false,
                'message' => 'WHATSAPP_PHONE_NUMBER_ID is not configured.',
                'display_phone_number' => null,
                'verified_name' => null,
            ];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$phoneNumberId, [
                    'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status',
                ]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'WhatsApp Graph request failed: '.$e->getMessage(),
                'display_phone_number' => null,
                'verified_name' => null,
            ];
        }

        if (! $response->successful()) {
            $error = (string) data_get($response->json(), 'error.message', $response->body());

            return [
                'ok' => false,
                'message' => 'WhatsApp Cloud API rejected the token/phone id: '.$error,
                'display_phone_number' => null,
                'verified_name' => null,
            ];
        }

        $display = (string) $response->json('display_phone_number', '');
        $name = (string) $response->json('verified_name', '');

        return [
            'ok' => true,
            'message' => 'WhatsApp Cloud API credentials are valid'
                .($display !== '' ? ' ('.$display.')' : '')
                .'. Incoming chats still require the webhook at '
                .rtrim((string) config('app.url'), '/').'/api/webhooks/whatsapp'
                .' subscribed to the messages field.',
            'display_phone_number' => $display !== '' ? $display : null,
            'verified_name' => $name !== '' ? $name : null,
        ];
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/whatsapp';
    }
}
