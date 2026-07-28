<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncMessengerConversationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_artisan_command_syncs_graph_conversations(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_cmd_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_CMD_1', 'name' => 'Cron Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_cmd_1',
                            'message' => 'Hello from cron sync',
                            'from' => ['id' => 'PSID_CMD_1', 'name' => 'Cron Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $exit = Artisan::call('messenger:sync-conversations');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Synced 1 conversation', Artisan::output());
        $this->assertDatabaseHas('channel_conversations', [
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_CMD_1',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_cmd_1',
            'body' => 'Hello from cron sync',
        ]);
    }

    public function test_http_sync_endpoint_requires_token_and_imports_threads(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.messenger.sync_token' => 'secret-sync-token',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_http_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_HTTP_1', 'name' => 'Http Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_http_1',
                            'message' => 'Hello from http sync',
                            'from' => ['id' => 'PSID_HTTP_1', 'name' => 'Http Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->get('/internal/messenger/sync-conversations')->assertForbidden();
        $this->get('/internal/messenger/sync-conversations?token=wrong')->assertForbidden();

        $this->get('/internal/messenger/sync-conversations?token=secret-sync-token')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conversations', 1);

        $this->assertDatabaseHas('channel_conversations', [
            'external_user_id' => 'PSID_HTTP_1',
        ]);
    }

    public function test_graph_sync_stores_each_attachment_as_separate_message(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_album_1',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_ALBUM_SYNC', 'name' => 'Album Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_graph_album',
                            'message' => 'Pick one',
                            'from' => ['id' => 'PSID_ALBUM_SYNC', 'name' => 'Album Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                            'attachments' => [
                                'data' => [
                                    [
                                        'id' => 'att1',
                                        'mime_type' => 'image/jpeg',
                                        'name' => 'a.jpg',
                                        'image_data' => ['url' => 'https://cdn.example.com/a.jpg'],
                                    ],
                                    [
                                        'id' => 'att2',
                                        'mime_type' => 'image/jpeg',
                                        'name' => 'b.jpg',
                                        'image_data' => ['url' => 'https://cdn.example.com/b.jpg'],
                                    ],
                                    [
                                        'id' => 'att3',
                                        'mime_type' => 'image/jpeg',
                                        'name' => 'c.jpg',
                                        'image_data' => ['url' => 'https://cdn.example.com/c.jpg'],
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->assertSame(0, Artisan::call('messenger:sync-conversations'));

        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_graph_album#0',
            'body' => 'Pick one',
            'media_url' => 'https://cdn.example.com/a.jpg',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_graph_album#1',
            'media_url' => 'https://cdn.example.com/b.jpg',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_graph_album#2',
            'media_url' => 'https://cdn.example.com/c.jpg',
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'external_message_id' => 'm_graph_album',
        ]);
    }

    public function test_graph_sync_backfills_remaining_album_images_when_legacy_bare_mid_exists(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_LEGACY_ALBUM',
            'external_account_id' => 'PAGE42',
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_legacy_album',
            'direction' => 'inbound',
            'body' => 'Old first only',
            'media_url' => 'https://cdn.example.com/old-a.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/PAGE42/conversations*' => Http::response([
                'data' => [[
                    'id' => 't_legacy_album',
                    'updated_time' => now()->toIso8601String(),
                    'participants' => [
                        'data' => [
                            ['id' => 'PAGE42', 'name' => 'Sun Page'],
                            ['id' => 'PSID_LEGACY_ALBUM', 'name' => 'Legacy Customer'],
                        ],
                    ],
                    'messages' => [
                        'data' => [[
                            'id' => 'm_legacy_album',
                            'message' => 'Old first only',
                            'from' => ['id' => 'PSID_LEGACY_ALBUM', 'name' => 'Legacy Customer'],
                            'created_time' => now()->subMinute()->toIso8601String(),
                            'attachments' => [
                                'data' => [
                                    ['image_data' => ['url' => 'https://cdn.example.com/a.jpg']],
                                    ['image_data' => ['url' => 'https://cdn.example.com/b.jpg']],
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        Artisan::call('messenger:sync-conversations');

        // Bare mid kept as the first image; remaining album images are backfilled.
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_legacy_album',
            'media_url' => 'https://cdn.example.com/old-a.jpg',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_legacy_album#1',
            'media_url' => 'https://cdn.example.com/b.jpg',
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'external_message_id' => 'm_legacy_album#0',
        ]);
    }

    public function test_messenger_sync_is_scheduled_when_auto_sync_enabled(): void
    {
        config(['facebook.messenger.auto_sync_enabled' => true]);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'messenger:sync-conversations'));

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->filtersPass(app()));
    }

    public function test_messenger_sync_schedule_skips_when_auto_sync_disabled(): void
    {
        config(['facebook.messenger.auto_sync_enabled' => false]);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'messenger:sync-conversations'));

        $this->assertCount(1, $events);
        $this->assertFalse($events->first()->filtersPass(app()));
    }
}
