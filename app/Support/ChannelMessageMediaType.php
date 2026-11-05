<?php

namespace App\Support;

/**
 * Messenger stamps voice clips as audio/mpeg even when the bytes are AAC in MP4.
 * Browsers honour X-Content-Type-Options: nosniff, so the real type has to win.
 */
class ChannelMessageMediaType
{
    public static function detect(string $bytes, ?string $declaredMime = null, bool $audioAttachment = false): string
    {
        $declared = self::normalize($declaredMime);
        $magic = self::magic($bytes, $audioAttachment, $declared);

        if ($magic !== null) {
            return $magic;
        }

        return $declared !== '' ? $declared : 'application/octet-stream';
    }

    /**
     * Facebook error pages and Graph JSON must not be treated as playable audio.
     */
    public static function isUsableMedia(string $bytes): bool
    {
        $start = ltrim(substr($bytes, 0, 96));
        if ($start === '') {
            return false;
        }

        $first = $start[0];

        return $first !== '{' && $first !== '<' && $first !== '[';
    }

    public static function extension(?string $mime): string
    {
        $mime = self::normalize($mime);

        return match (true) {
            in_array($mime, ['audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/x-aac'], true) => 'm4a',
            in_array($mime, ['audio/mpeg', 'audio/mp3'], true) => 'mp3',
            in_array($mime, ['audio/ogg', 'audio/opus'], true) => 'ogg',
            in_array($mime, ['audio/wav', 'audio/wave', 'audio/x-wav'], true) => 'wav',
            $mime === 'audio/webm' => 'webm',
            $mime === 'audio/amr' => 'amr',
            str_starts_with($mime, 'audio/') => 'm4a',
            str_starts_with($mime, 'video/webm') => 'webm',
            str_starts_with($mime, 'video/') => 'mp4',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'jpg',
        };
    }

    public static function filename(?string $mime): string
    {
        $normalized = self::normalize($mime);
        $base = match (true) {
            str_starts_with($normalized, 'audio/') => 'voice',
            str_starts_with($normalized, 'video/') => 'clip',
            default => 'photo',
        };

        return $base.'.'.self::extension($mime);
    }

    /**
     * Hint for <source type>. Messenger stamps AAC/MP4 notes as audio/mpeg.
     */
    public static function htmlAudioType(?string $mime): string
    {
        $normalized = self::normalize($mime);
        if (str_starts_with($normalized, 'audio/') && ! in_array($normalized, ['audio/mpeg', 'audio/mp3'], true)) {
            return $normalized;
        }

        return 'audio/mp4';
    }

    private static function magic(string $bytes, bool $audioAttachment, string $declared): ?string
    {
        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if ($audioAttachment || str_starts_with($declared, 'audio/') || in_array($brand, ['M4A ', 'M4B ', 'M4P '], true)) {
                return 'audio/mp4';
            }

            return 'video/mp4';
        }

        if (str_starts_with($bytes, 'ID3') || self::looksLikeMpegFrame($bytes)) {
            return 'audio/mpeg';
        }

        if (str_starts_with($bytes, 'OggS')) {
            return 'audio/ogg';
        }

        if (str_starts_with($bytes, 'RIFF') && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }

        if (str_starts_with($bytes, '#!AMR')) {
            return 'audio/amr';
        }

        if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            return str_starts_with($declared, 'audio/') || $audioAttachment
                ? 'audio/webm'
                : 'video/webm';
        }

        return null;
    }

    private static function looksLikeMpegFrame(string $bytes): bool
    {
        if (strlen($bytes) < 2) {
            return false;
        }

        $first = ord($bytes[0]);
        $second = ord($bytes[1]);

        return $first === 0xFF && ($second & 0xE0) === 0xE0;
    }

    private static function normalize(?string $mime): string
    {
        if (! is_string($mime) || $mime === '') {
            return '';
        }

        return strtolower(trim(explode(';', $mime, 2)[0]));
    }
}
