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
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition', 'inline');
        $this->assertSame($bytes, $response->streamedContent());
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
}
