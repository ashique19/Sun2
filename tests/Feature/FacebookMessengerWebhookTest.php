<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FacebookMessengerWebhookTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_webhook_disabled_when_enabled_is_false(): void
    {
        config([
            'facebook.messenger.enabled' => false,
        ]);

        // GET verification should return 503
        $this->get('/api/webhooks/messenger?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'any-token',
            'hub.challenge' => '1234567890',
        ]))->assertStatus(503)->assertSee('Messenger webhook disabled');

        // POST event should also return 503
        $this->post('/api/webhooks/messenger', [
            'object' => 'page',
            'entry' => [],
        ], ['Content-Type' => 'application/json'])
            ->assertStatus(503)
            ->assertSee('Messenger webhook disabled');
    }

    public function test_handles_various_attachment_types(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'gemini.api_key' => null,
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE123',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => 'PSID_ATTACH'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_attach_test',
                        'text' => 'Product image',
                        'attachments' => [
                            [
                                'type' => 'image',
                                'payload' => [
                                    'url' => 'https://example.com/image.jpg',
                                    'mime_type' => 'image/png',
                                ],
                            ],
                            [
                                'type' => 'fallback',
                                'payload' => [
                                    'url' => 'https://example.com/fallback.jpg',
                                ],
                            ],
                            [
                                'type' => 'video',
                                'payload' => [
                                    'url' => 'https://example.com/video.mp4',
                                    'mime_type' => 'video/mp4',
                                ],
                            ],
                            // This should be ignored (location type)
                            [
                                'type' => 'location',
                                'payload' => ['coordinates' => []],
                            ],
                        ],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk()->assertSee('EVENT_RECEIVED', false);

        // Check that a conversation was created
        $this->assertDatabaseHas('channel_conversations', [
            'channel' => 'messenger',
            'external_user_id' => 'PSID_ATTACH',
        ]);

        // Check that a message was stored with media URL
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_attach_test',
            'body' => 'Product image',
        ]);

        // The message should have the first valid attachment URL (image)
        $message = \App\Models\ChannelMessage::where('external_message_id', 'm_attach_test')->first();
        $this->assertNotNull($message);
        $this->assertEquals('https://example.com/image.jpg', $message->media_url);
        $this->assertEquals('image/png', $message->media_mime);
    }

    public function test_ignores_echo_messages(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE123',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => 'PSID_ECHO'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_echo_test',
                        'text' => 'This is an echo',
                        'is_echo' => true,
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk()->assertSee('EVENT_RECEIVED', false);

        // Echo messages should NOT create a conversation or store message
        $this->assertDatabaseMissing('channel_conversations', [
            'external_user_id' => 'PSID_ECHO',
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'external_message_id' => 'm_echo_test',
        ]);
    }
}
