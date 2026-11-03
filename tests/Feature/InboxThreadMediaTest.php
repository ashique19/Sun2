<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Admin\InboxSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxThreadMediaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function thread_renders_reply_quote_audio_video_and_emoji(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $photo = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_photo',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/photo.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinutes(4),
        ]);

        $reply = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_reply',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'This one 😍',
            'reply_to_message_id' => $photo->id,
            'sent_at' => now()->subMinutes(3),
        ]);

        $audio = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_audio',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://cdn.fbsbx.com/voice.m4a',
            'media_mime' => 'audio/mpeg',
            'raw_payload' => [
                'message' => [
                    'attachments' => [[
                        'type' => 'audio',
                        'payload' => ['url' => 'https://cdn.fbsbx.com/voice.m4a'],
                    ]],
                ],
            ],
            'sent_at' => now()->subMinutes(2),
        ]);

        $video = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_video',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://scontent.xx.fbcdn.net/clip.mp4',
            'media_mime' => 'video/mp4',
            'raw_payload' => [
                'attachments' => [
                    'data' => [[
                        'video_data' => [
                            'url' => 'https://scontent.xx.fbcdn.net/clip.mp4',
                            'preview_url' => 'https://scontent.xx.fbcdn.net/thumb.jpg',
                        ],
                    ]],
                ],
            ],
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('This one 😍')
            ->assertSeeHtml('id="inbox-msg-'.$reply->id.'"')
            ->assertSeeHtml('data-inbox-quote-target="'.$photo->id.'"')
            ->assertSeeHtml('href="#inbox-msg-'.$photo->id.'"')
            ->assertSeeHtml('<audio')
            ->assertSeeHtml('wire:key="inbox-audio-'.$audio->id.'"')
            ->assertSeeHtml('src="'.e($audio->staffMediaUrl()).'"')
            ->assertSeeHtml('<video')
            ->assertSeeHtml('src="'.e($video->staffMediaUrl()).'"')
            ->assertSeeHtml('poster="'.e($video->staffMediaUrl(poster: true)).'"')
            ->assertSeeHtml('inbox-msg-body')
            ->assertDontSee('View attachment');
    }

    #[Test]
    public function snapshot_includes_playable_media_and_quoted_thumbnail(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $photo = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_snap_photo',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/photo.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinutes(2),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_snap_reply',
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => 'Yes 👍',
            'reply_to_message_id' => $photo->id,
            'sent_at' => now()->subMinute(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_snap_audio',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://cdn.fbsbx.com/voice.m4a',
            'media_mime' => 'audio/mpeg',
            'sent_at' => now(),
        ]);

        $payload = (new InboxSnapshotBuilder)->thread($conversation);

        $this->assertCount(3, $payload['messages']);
        $this->assertTrue($payload['messages'][0]['is_image']);
        $this->assertNotNull($payload['messages'][0]['media_url']);
        $this->assertSame($photo->id, $payload['messages'][1]['reply_to_id']);
        $this->assertTrue($payload['messages'][1]['reply_is_image']);
        $this->assertNotNull($payload['messages'][1]['reply_media_url']);
        $this->assertSame('Yes 👍', $payload['messages'][1]['body']);
        $this->assertTrue($payload['messages'][2]['is_audio']);
        $this->assertFalse($payload['messages'][2]['is_image']);
        $this->assertNotNull($payload['messages'][2]['media_url']);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-media-thread',
            'customer_name' => 'Media Customer',
            'last_inbound_at' => now(),
        ]);
    }
}
