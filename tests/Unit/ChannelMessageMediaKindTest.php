<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelMessageMediaKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_audio_even_when_hosted_on_facebook_cdn(): void
    {
        $message = $this->message([
            'media_url' => 'https://cdn.fbsbx.com/v/audio-clip.mp4?_nc_ohc=abc',
            'media_mime' => 'audio/mpeg',
            'raw_payload' => [
                'message' => [
                    'attachments' => [[
                        'type' => 'audio',
                        'payload' => ['url' => 'https://cdn.fbsbx.com/v/audio-clip.mp4?_nc_ohc=abc'],
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($message->isAudioAttachment());
        $this->assertFalse($message->isImageAttachment());
        $this->assertFalse($message->isVideoAttachment());
        $this->assertSame('Audio', $message->previewText());
        $this->assertSame(ChannelMessage::MEDIA_AUDIO, $message->mediaKind());
    }

    public function test_classifies_video_from_mime_and_graph_payload(): void
    {
        $message = $this->message([
            'media_url' => 'https://scontent.xx.fbcdn.net/v/video.mp4',
            'media_mime' => 'video/mp4',
            'raw_payload' => [
                'attachments' => [
                    'data' => [[
                        'mime_type' => 'video/mp4',
                        'video_data' => [
                            'url' => 'https://scontent.xx.fbcdn.net/v/video.mp4',
                            'preview_url' => 'https://scontent.xx.fbcdn.net/v/thumb.jpg',
                        ],
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($message->isVideoAttachment());
        $this->assertFalse($message->isImageAttachment());
        $this->assertSame('Video', $message->previewText());
        $this->assertSame('https://scontent.xx.fbcdn.net/v/thumb.jpg', $message->videoPosterUrl());
    }

    public function test_stickers_preview_as_sticker_and_still_count_as_images(): void
    {
        $url = 'https://scontent.xx.fbcdn.net/v/sticker.png';
        $message = $this->message([
            'media_url' => $url,
            'media_mime' => 'image/png',
            'raw_payload' => [
                'message' => [
                    'sticker_id' => 369239263222822,
                    'attachments' => [[
                        'type' => 'image',
                        'payload' => [
                            'url' => $url,
                            'sticker_id' => 369239263222822,
                        ],
                    ]],
                ],
            ],
        ]);

        $this->assertTrue($message->isStickerAttachment());
        $this->assertTrue($message->isImageAttachment());
        $this->assertSame('Sticker', $message->previewText());
    }

    public function test_unicode_emoji_body_is_kept_as_preview(): void
    {
        $message = $this->message([
            'body' => '😍🔥',
        ]);

        $this->assertSame('😍🔥', $message->previewText());
        $this->assertNull($message->mediaKind());
    }

    public function test_unresolved_reply_to_mid_reads_webhook_and_graph_shapes(): void
    {
        $webhook = $this->message([
            'body' => 'ok',
            'raw_payload' => [
                'message' => [
                    'reply_to' => ['mid' => 'm_parent_webhook'],
                ],
            ],
        ]);
        $graph = $this->message([
            'body' => 'ok',
            'raw_payload' => [
                'reply_to' => ['mid' => 'm_parent_graph'],
            ],
        ]);
        $parent = $this->message(['body' => 'parent']);
        $linked = $this->message([
            'body' => 'ok',
            'channel_conversation_id' => $parent->channel_conversation_id,
            'reply_to_message_id' => $parent->id,
            'raw_payload' => [
                'reply_to' => ['mid' => 'm_ignored'],
            ],
        ]);

        $this->assertSame('m_parent_webhook', $webhook->unresolvedReplyToMid());
        $this->assertSame('m_parent_graph', $graph->unresolvedReplyToMid());
        $this->assertNull($linked->unresolvedReplyToMid());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function message(array $overrides): ChannelMessage
    {
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-media-kind-'.uniqid(),
            'customer_name' => 'Kind Customer',
            'last_inbound_at' => now(),
        ]);

        return ChannelMessage::query()->create(array_merge([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'sent_at' => now(),
        ], $overrides));
    }
}
