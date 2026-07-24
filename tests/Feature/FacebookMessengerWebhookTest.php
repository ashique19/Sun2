<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FacebookMessengerWebhookTest extends TestCase
{
    public function test_verify_challenge_succeeds_with_matching_token(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.verify_token' => 'sun2-verify-secret',
        ]);

        $this->get('/api/webhooks/messenger?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'sun2-verify-secret',
            'hub.challenge' => '1234567890',
        ]))
            ->assertOk()
            ->assertSee('1234567890', false);
    }

    public function test_verify_challenge_rejects_wrong_token(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.verify_token' => 'sun2-verify-secret',
        ]);

        $this->get('/api/webhooks/messenger?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong',
            'hub.challenge' => '1234567890',
        ]))->assertForbidden();
    }

    public function test_receive_accepts_signed_event_payload(): void
    {
        $secret = 'app-secret-xyz';
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => $secret,
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [
                ['id' => '1', 'time' => time(), 'messaging' => []],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        Log::spy();

        $this->call(
            'POST',
            '/api/webhooks/messenger',
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

    public function test_receive_rejects_bad_signature_when_secret_configured(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => 'app-secret-xyz',
        ]);

        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef',
            ],
            '{"object":"page","entry":[]}',
        )->assertUnauthorized();
    }
}
