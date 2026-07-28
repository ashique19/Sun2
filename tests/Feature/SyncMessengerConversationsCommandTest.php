<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
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
