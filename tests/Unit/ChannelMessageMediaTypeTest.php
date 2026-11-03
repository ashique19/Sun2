<?php

namespace Tests\Unit;

use App\Support\ChannelMessageMediaType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelMessageMediaTypeTest extends TestCase
{
    #[Test]
    public function messenger_voice_m4a_is_audio_mp4_even_when_declared_mpeg(): void
    {
        $bytes = "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00mp42";

        $this->assertSame(
            'audio/mp4',
            ChannelMessageMediaType::detect($bytes, 'audio/mpeg', audioAttachment: true),
        );
    }

    #[Test]
    public function isom_mp4_voice_clip_is_audio_mp4_when_the_message_is_audio(): void
    {
        $bytes = "\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isom";

        $this->assertSame(
            'audio/mp4',
            ChannelMessageMediaType::detect($bytes, 'audio/mpeg', audioAttachment: true),
        );
    }

    #[Test]
    public function id3_mp3_stays_audio_mpeg(): void
    {
        $bytes = 'ID3'.str_repeat("\x00", 20);

        $this->assertSame(
            'audio/mpeg',
            ChannelMessageMediaType::detect($bytes, 'audio/mpeg', audioAttachment: true),
        );
    }

    #[Test]
    public function video_ftyp_stays_video_mp4(): void
    {
        $bytes = "\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isom";

        $this->assertSame(
            'video/mp4',
            ChannelMessageMediaType::detect($bytes, 'video/mp4', audioAttachment: false),
        );
    }
}
