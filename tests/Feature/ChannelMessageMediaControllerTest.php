<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChannelMessageMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function audioMessage(): ChannelMessage
    {
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-voice',
            'customer_name' => 'Voice Customer',
            'last_inbound_at' => now(),
        ]);

        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_voice_clip',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://cdn.fbsbx.com/voice.m4a',
            'media_mime' => 'audio/mpeg',
            'raw_payload' => [
                'message' => [
                    'attachments' => [[
                        'type' => 'audio',
                        'payload' => [
                            'url' => 'https://cdn.fbsbx.com/voice.m4a',
                            'mime_type' => 'audio/mpeg',
                        ],
                    ]],
                ],
            ],
            'sent_at' => now(),
        ]);
    }

    private function m4aBytes(int $length = 256): string
    {
        $header = "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00mp42";

        return str_pad($header, $length, "\x00");
    }

    #[Test]
    public function voice_clip_labeled_mpeg_is_served_as_audio_mp4_with_ranges(): void
    {
        config(['facebook.messenger.page_access_token' => 'page-token']);
        $bytes = $this->m4aBytes();
        Http::fake([
            'cdn.fbsbx.com/*' => Http::response($bytes, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->actingAs($this->adminUser());
        $message = $this->audioMessage();

        $response = $this->get(route('admin.inbox.media', $message));

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('voice.m4a', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertNotNull($message->fresh()->media_path);
        $this->assertFileExists(public_path($message->fresh()->media_path));
    }

    #[Test]
    public function audio_player_range_request_returns_partial_content(): void
    {
        config(['facebook.messenger.page_access_token' => 'page-token']);
        $bytes = $this->m4aBytes(200);
        Http::fake([
            'cdn.fbsbx.com/*' => Http::response($bytes, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->actingAs($this->adminUser());
        $message = $this->audioMessage();

        $response = $this->get(route('admin.inbox.media', $message), [
            'Range' => 'bytes=0-1',
        ]);

        $response->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Content-Range', 'bytes 0-1/200');
        $this->assertSame(substr($bytes, 0, 2), $response->streamedContent());
    }

    #[Test]
    public function voice_clip_player_url_ends_with_m4a_and_replays_from_disk(): void
    {
        config(['facebook.messenger.page_access_token' => 'page-token']);
        $bytes = $this->m4aBytes();
        Http::fake([
            'cdn.fbsbx.com/*' => Http::response($bytes, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->actingAs($this->adminUser());
        $message = $this->audioMessage();

        $this->assertStringEndsWith('/file/voice.m4a', $message->staffMediaUrl());

        $this->get($message->staffMediaUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4');

        Http::fake([
            '*' => Http::response('should-not-refetch', 500),
        ]);

        $replay = $this->get($message->staffMediaUrl());
        $replay->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4');
        $this->assertSame($bytes, $replay->streamedContent());
    }

    #[Test]
    public function expired_facebook_audio_url_is_refreshed_from_graph(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);
        $bytes = $this->m4aBytes();
        Http::fake([
            'cdn.fbsbx.com/expired*' => Http::response('<html>gone</html>', 403),
            'graph.facebook.com/*' => Http::response([
                'attachments' => [
                    'data' => [[
                        'mime_type' => 'audio/mpeg',
                        'file_url' => 'https://cdn.fbsbx.com/fresh.m4a',
                    ]],
                ],
            ]),
            'cdn.fbsbx.com/fresh*' => Http::response($bytes, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->actingAs($this->adminUser());
        $message = $this->audioMessage();
        $message->forceFill([
            'media_url' => 'https://cdn.fbsbx.com/expired.m4a',
        ])->save();

        $response = $this->get(route('admin.inbox.media.file', [
            'message' => $message,
            'file' => 'voice.m4a',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4');
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('https://cdn.fbsbx.com/fresh.m4a', $message->fresh()->media_url);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request->url(), 'm_voice_clip'));
    }

    #[Test]
    public function html_error_body_is_not_served_as_audio(): void
    {
        config(['facebook.messenger.page_access_token' => 'page-token']);
        Http::fake([
            'cdn.fbsbx.com/*' => Http::response('<!DOCTYPE html><html>login</html>', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400),
        ]);

        $this->actingAs($this->adminUser());
        $message = $this->audioMessage();

        $this->get(route('admin.inbox.media', $message))->assertNotFound();
    }
}
