<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    public function test_verify_challenge_succeeds_with_matching_token(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.verify_token' => 'wa-verify-secret',
        ]);

        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wa-verify-secret',
            'hub.challenge' => 'challenge-wa',
        ]))
            ->assertOk()
            ->assertSee('challenge-wa', false);
    }

    public function test_verify_challenge_rejects_wrong_token(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.verify_token' => 'wa-verify-secret',
        ]);

        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong',
            'hub.challenge' => 'challenge-wa',
        ]))->assertForbidden();
    }

    public function test_receive_rejects_bad_signature_when_secret_configured(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => 'wa-secret',
        ]);

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef',
            ],
            '{"object":"whatsapp_business_account","entry":[]}',
        )->assertUnauthorized();
    }

    public function test_receive_accepts_signed_empty_payload(): void
    {
        $secret = 'wa-secret';
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => $secret,
        ]);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        Log::spy();

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => $signature,
            ],
            $body,
        )
            ->assertOk()
            ->assertSee('EVENT_RECEIVED', false);
    }
}
