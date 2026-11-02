<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChannelMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const MEDIA_IMAGE = 'image';

    public const MEDIA_AUDIO = 'audio';

    public const MEDIA_VIDEO = 'video';

    public const MEDIA_STICKER = 'sticker';

    public const MEDIA_FILE = 'file';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel_conversation_id' => 'integer',
            'reply_to_message_id' => 'integer',
            'matched_product_id' => 'integer',
            'raw_payload' => 'array',
            'sent_at' => 'datetime',
            'image_match_checked_at' => 'datetime',
            'image_match_meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'channel_conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }

    public function hasMedia(): bool
    {
        return filled($this->media_url) || filled($this->media_path);
    }

    public function localMediaPath(): ?string
    {
        $path = trim((string) ($this->media_path ?? ''));
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($relative, '..')) {
            return null;
        }

        $absolute = public_path($relative);

        return is_file($absolute) ? $relative : null;
    }

    public function previewText(int $limit = 80): string
    {
        $body = trim((string) ($this->body ?? ''));
        if ($body !== '') {
            return Str::limit($body, $limit);
        }

        return match ($this->mediaKind()) {
            self::MEDIA_STICKER => 'Sticker',
            self::MEDIA_IMAGE => 'Photo',
            self::MEDIA_AUDIO => 'Audio',
            self::MEDIA_VIDEO => 'Video',
            self::MEDIA_FILE => 'Attachment',
            default => 'Message',
        };
    }

    public function mediaKind(): ?string
    {
        if (! $this->hasMedia()) {
            return null;
        }

        if ($this->isAudioAttachment()) {
            return self::MEDIA_AUDIO;
        }

        if ($this->isVideoAttachment()) {
            return self::MEDIA_VIDEO;
        }

        if ($this->isStickerAttachment()) {
            return self::MEDIA_STICKER;
        }

        if ($this->isImageAttachment()) {
            return self::MEDIA_IMAGE;
        }

        return self::MEDIA_FILE;
    }

    public function isImageAttachment(): bool
    {
        if (! $this->hasMedia() || $this->isAudioAttachment() || $this->isVideoAttachment()) {
            return false;
        }

        $mime = strtolower((string) ($this->media_mime ?? ''));
        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $path = (string) ($this->media_path ?: $this->media_url);
        if (preg_match('/\.(jpe?g|png|gif|webp|bmp)(\?|$)/i', $path) === 1) {
            return true;
        }

        $url = (string) ($this->media_url ?? '');
        if ($url === '') {
            return filled($this->media_path);
        }

        $attachment = $this->matchingAttachment();
        if ($attachment !== null) {
            if (($attachment['type'] ?? null) === 'image'
                || data_get($attachment, 'image_data')
                || filled(data_get($attachment, 'payload.sticker_id'))) {
                return true;
            }

            if (($attachment['type'] ?? null) === 'video' || data_get($attachment, 'video_data')) {
                return false;
            }
        }

        if ($this->graphStickerUrl() === $url) {
            return true;
        }

        // Match this row's media URL to the corresponding Messenger attachment
        // (albums store one ChannelMessage per attachment with the full payload).
        // Meta CDN/lookaside URLs rarely include a file extension.
        return str_contains($url, 'fbcdn')
            || str_contains($url, 'fbsbx.com')
            || str_contains($url, 'lookaside');
    }

    public function isAudioAttachment(): bool
    {
        if (! $this->hasMedia() || $this->hasMimePrefix('video/') || $this->hasMimePrefix('image/')) {
            return false;
        }

        if ($this->hasMimePrefix('audio/')) {
            return true;
        }

        $path = (string) ($this->media_path ?: $this->media_url);
        if (preg_match('/\.(mp3|m4a|aac|ogg|wav|amr|opus)(\?|$)/i', $path) === 1) {
            return true;
        }

        $attachment = $this->matchingAttachment();

        return ($attachment['type'] ?? null) === 'audio'
            || str_starts_with(strtolower((string) data_get($attachment, 'mime_type', '')), 'audio/');
    }

    public function isVideoAttachment(): bool
    {
        if (! $this->hasMedia() || $this->hasMimePrefix('audio/') || $this->hasMimePrefix('image/')) {
            return false;
        }

        if ($this->hasMimePrefix('video/')) {
            return true;
        }

        $path = (string) ($this->media_path ?: $this->media_url);
        if (preg_match('/\.(mp4|mov|webm|m4v|3gp)(\?|$)/i', $path) === 1) {
            return true;
        }

        $attachment = $this->matchingAttachment();

        return ($attachment['type'] ?? null) === 'video'
            || filled(data_get($attachment, 'video_data'))
            || str_starts_with(strtolower((string) data_get($attachment, 'mime_type', '')), 'video/');
    }

    public function isStickerAttachment(): bool
    {
        if (! $this->hasMedia()) {
            return false;
        }

        if (filled(data_get($this->raw_payload, 'message.sticker_id'))
            || filled(data_get($this->raw_payload, 'sticker_id'))
            || filled($this->graphStickerUrl())) {
            return true;
        }

        $attachment = $this->matchingAttachment();

        return ($attachment['type'] ?? null) === 'sticker'
            || filled(data_get($attachment, 'payload.sticker_id'));
    }

    public function staffMediaUrl(bool $poster = false): string
    {
        return route('admin.inbox.media', $poster
            ? ['message' => $this, 'poster' => 1]
            : $this);
    }

    public function videoPosterUrl(): ?string
    {
        if (! $this->isVideoAttachment()) {
            return null;
        }

        $preview = data_get($this->matchingAttachment(), 'video_data.preview_url')
            ?? data_get($this->matchingAttachment(), 'payload.preview_url')
            ?? data_get($this->raw_payload, 'attachments.data.0.video_data.preview_url');

        return is_string($preview) && $preview !== '' ? $preview : null;
    }

    public function unresolvedReplyToMid(): ?string
    {
        if ($this->reply_to_message_id) {
            return null;
        }

        $mid = data_get($this->raw_payload, 'message.reply_to.mid')
            ?? data_get($this->raw_payload, 'reply_to.mid');

        return is_string($mid) && $mid !== '' ? $mid : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingAttachment(): ?array
    {
        $url = (string) ($this->media_url ?? '');
        $attachments = data_get($this->raw_payload, 'message.attachments')
            ?? data_get($this->raw_payload, 'attachments.data')
            ?? data_get($this->raw_payload, 'attachments');

        if (! is_array($attachments) || $attachments === []) {
            return null;
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            if ($url === '') {
                return $attachment;
            }

            $attachmentUrl = data_get($attachment, 'payload.url')
                ?? data_get($attachment, 'image_data.url')
                ?? data_get($attachment, 'file_url')
                ?? data_get($attachment, 'video_data.url');

            if (is_string($attachmentUrl) && $attachmentUrl === $url) {
                return $attachment;
            }
        }

        return null;
    }

    private function graphStickerUrl(): ?string
    {
        $sticker = data_get($this->raw_payload, 'sticker');

        return is_string($sticker) && $sticker !== '' ? $sticker : null;
    }

    private function hasMimePrefix(string $prefix): bool
    {
        return str_starts_with(strtolower((string) ($this->media_mime ?? '')), $prefix);
    }
}
