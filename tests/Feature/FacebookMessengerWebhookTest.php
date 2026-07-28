<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
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

        // Each media attachment becomes its own inbox message (Messenger albums / multi-attach).
        $this->assertSame(3, ChannelMessage::query()->where('external_message_id', 'like', 'm_attach_test%')->count());

        $first = ChannelMessage::where('external_message_id', 'm_attach_test#0')->first();
        $this->assertNotNull($first);
        $this->assertSame('Product image', $first->body);
        $this->assertSame('https://example.com/image.jpg', $first->media_url);
        $this->assertSame('image/png', $first->media_mime);
        $this->assertTrue($first->isImageAttachment());

        $second = ChannelMessage::where('external_message_id', 'm_attach_test#1')->first();
        $this->assertNotNull($second);
        $this->assertNull($second->body);
        $this->assertSame('https://example.com/fallback.jpg', $second->media_url);

        $third = ChannelMessage::where('external_message_id', 'm_attach_test#2')->first();
        $this->assertNotNull($third);
        $this->assertSame('https://example.com/video.mp4', $third->media_url);
        $this->assertSame('video/mp4', $third->media_mime);
        $this->assertFalse($third->isImageAttachment());
    }

    public function test_stores_each_image_in_messenger_album_as_separate_message(): void
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
                    'sender' => ['id' => 'PSID_ALBUM'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_album_3',
                        'text' => 'Which one?',
                        'attachments' => [
                            [
                                'type' => 'image',
                                'payload' => ['url' => 'https://example.com/a.jpg', 'mime_type' => 'image/jpeg'],
                            ],
                            [
                                'type' => 'image',
                                'payload' => ['url' => 'https://example.com/b.jpg', 'mime_type' => 'image/jpeg'],
                            ],
                            [
                                'type' => 'image',
                                'payload' => ['url' => 'https://example.com/c.jpg', 'mime_type' => 'image/jpeg'],
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

        $messages = ChannelMessage::query()
            ->where('external_message_id', 'like', 'm_album_3%')
            ->orderBy('external_message_id')
            ->get();

        $this->assertCount(3, $messages);
        $this->assertSame(['m_album_3#0', 'm_album_3#1', 'm_album_3#2'], $messages->pluck('external_message_id')->all());
        $this->assertSame('Which one?', $messages[0]->body);
        $this->assertNull($messages[1]->body);
        $this->assertNull($messages[2]->body);
        $this->assertSame([
            'https://example.com/a.jpg',
            'https://example.com/b.jpg',
            'https://example.com/c.jpg',
        ], $messages->pluck('media_url')->all());
        $this->assertTrue($messages->every(fn (ChannelMessage $message) => $message->isImageAttachment()));
    }

    public function test_single_image_keeps_bare_mid_for_dedupe(): void
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
                    'sender' => ['id' => 'PSID_ONE'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_single_img',
                        'attachments' => [[
                            'type' => 'image',
                            'payload' => ['url' => 'https://example.com/one.jpg', 'mime_type' => 'image/jpeg'],
                        ]],
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
        )->assertOk();

        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_single_img',
            'media_url' => 'https://example.com/one.jpg',
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'external_message_id' => 'm_single_img#0',
        ]);
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

    public function test_ingests_standby_messages_when_page_inbox_is_primary(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'gemini.api_key' => null,
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE999',
                'time' => time(),
                // No messaging[] — customer traffic arrives on standby while Page Inbox owns the thread.
                'standby' => [[
                    'sender' => ['id' => 'PSID_CUSTOMER_2'],
                    'recipient' => ['id' => 'PAGE999'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_standby_customer',
                        'text' => 'Hi, is this necklace available?',
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

        $this->assertDatabaseHas('channel_conversations', [
            'channel' => 'messenger',
            'external_user_id' => 'PSID_CUSTOMER_2',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_standby_customer',
            'body' => 'Hi, is this necklace available?',
            'direction' => 'inbound',
        ]);
    }

    public function test_dedupes_same_mid_across_messaging_and_standby(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'gemini.api_key' => null,
        ]);

        $event = [
            'sender' => ['id' => 'PSID_DEDUP'],
            'recipient' => ['id' => 'PAGE1'],
            'timestamp' => (int) (microtime(true) * 1000),
            'message' => [
                'mid' => 'm_shared_mid',
                'text' => 'Duplicate delivery',
            ],
        ];

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'time' => time(),
                'messaging' => [$event],
                'standby' => [$event],
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
        )->assertOk();

        $this->assertSame(1, ChannelConversation::query()->where('external_user_id', 'PSID_DEDUP')->count());
        $this->assertSame(1, ChannelMessage::query()->where('external_message_id', 'm_shared_mid')->count());
    }
}
