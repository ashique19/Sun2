<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Services\Channels\MessengerConversationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessengerGraphPollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
            'channels.inbox.graph_poll_conversation_limit' => 15,
            'channels.inbox.graph_poll_messages_per_thread' => 8,
        ]);
    }

    #[Test]
    public function poll_fetches_messages_only_for_threads_newer_than_last_sync(): void
    {
        Cache::put(
            MessengerConversationSyncService::LAST_SYNC_CACHE_KEY,
            now()->subMinutes(5)->toIso8601String(),
        );

        $idsRequests = 0;
        $headerRequests = 0;

        Http::fake(function (Request $request) use (&$idsRequests, &$headerRequests) {
            $url = $request->url();

            if (str_contains($url, '/conversations')) {
                $headerRequests++;
                $this->assertStringContainsString('fields=id%2Cupdated_time', $url);
                $this->assertStringNotContainsString('messages.limit', $url);

                return Http::response([
                    'data' => [
                        [
                            'id' => 't_fresh',
                            'updated_time' => now()->subMinute()->toIso8601String(),
                        ],
                        [
                            'id' => 't_stale',
                            'updated_time' => now()->subHours(2)->toIso8601String(),
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'ids=')) {
                $idsRequests++;
                $this->assertStringContainsString('t_fresh', $url);
                $this->assertStringNotContainsString('t_stale', $url);
                $this->assertStringContainsString('messages.limit%288%29', $url);

                return Http::response([
                    't_fresh' => $this->threadPayload(
                        't_fresh',
                        'PSID_FRESH',
                        'Fresh Customer',
                        'm_fresh',
                        'Hello after last sync',
                    ),
                ], 200);
            }

            return Http::response(['error' => ['message' => 'Unexpected '.$url]], 404);
        });

        $result = app(MessengerConversationSyncService::class)->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['conversations']);
        $this->assertSame(1, $result['messages']);
        $this->assertSame(1, $headerRequests);
        $this->assertSame(1, $idsRequests);
        $this->assertDatabaseHas('channel_conversations', [
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_FRESH',
        ]);
        $this->assertDatabaseMissing('channel_conversations', [
            'external_user_id' => 'PSID_STALE',
        ]);
    }

    #[Test]
    public function idle_poll_skips_message_fetch_when_nothing_is_newer(): void
    {
        Cache::put(
            MessengerConversationSyncService::LAST_SYNC_CACHE_KEY,
            now()->subMinute()->toIso8601String(),
        );

        $idsRequests = 0;

        Http::fake(function (Request $request) use (&$idsRequests) {
            $url = $request->url();

            if (str_contains($url, '/conversations')) {
                return Http::response([
                    'data' => [[
                        'id' => 't_old',
                        'updated_time' => now()->subHours(3)->toIso8601String(),
                    ]],
                ], 200);
            }

            if (str_contains($url, 'ids=')) {
                $idsRequests++;

                return Http::response(['error' => ['message' => 'should not fetch messages']], 500);
            }

            return Http::response(['error' => ['message' => 'Unexpected '.$url]], 404);
        });

        $result = app(MessengerConversationSyncService::class)->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['conversations']);
        $this->assertSame(0, $result['messages']);
        $this->assertSame(0, $result['graph_threads']);
        $this->assertSame(0, $idsRequests);
        $this->assertSame(0, ChannelConversation::query()->count());
        $this->assertStringContainsString('No Messenger conversations newer', $result['message']);
    }

    #[Test]
    public function full_sync_still_pulls_nested_messages_in_one_conversations_call(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            $this->assertStringContainsString('/conversations', $url);
            $this->assertStringContainsString('messages.limit%2830%29', $url);
            $this->assertStringNotContainsString('ids=', $url);

            return Http::response([
                'data' => [$this->threadPayload(
                    't_full',
                    'PSID_FULL',
                    'Full Customer',
                    'm_full',
                    'Catch-up hello',
                )],
            ], 200);
        });

        $result = app(MessengerConversationSyncService::class)->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['conversations']);
        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'm_full',
            'body' => 'Catch-up hello',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function threadPayload(
        string $threadId,
        string $psid,
        string $name,
        string $messageId,
        string $body,
    ): array {
        return [
            'id' => $threadId,
            'updated_time' => now()->toIso8601String(),
            'participants' => [
                'data' => [
                    ['id' => 'PAGE42', 'name' => 'Sun Page'],
                    ['id' => $psid, 'name' => $name],
                ],
            ],
            'messages' => [
                'data' => [[
                    'id' => $messageId,
                    'message' => $body,
                    'from' => ['id' => $psid, 'name' => $name],
                    'created_time' => now()->subMinute()->toIso8601String(),
                ]],
            ],
        ];
    }
}
