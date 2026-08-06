<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChannelMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel_conversation_id' => 'integer',
            'reply_to_message_id' => 'integer',
            'matched_product_id' => 'integer',
            'raw_payload' => 'array',
            'sent_at' => 'datetime',
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
        return filled($this->media_url);
    }

    public function previewText(int $limit = 80): string
    {
        $body = trim((string) ($this->body ?? ''));
        if ($body !== '') {
            return Str::limit($body, $limit);
        }

        if ($this->isImageAttachment()) {
            return 'Photo';
        }

        if ($this->hasMedia()) {
            return 'Attachment';
        }

        return 'Message';
    }

    public function isImageAttachment(): bool
    {
        if (! $this->hasMedia()) {
            return false;
        }

        $mime = strtolower((string) ($this->media_mime ?? ''));
        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $url = (string) $this->media_url;
        if (preg_match('/\.(jpe?g|png|gif|webp|bmp)(\?|$)/i', $url) === 1) {
            return true;
        }

        // Match this row's media URL to the corresponding Messenger attachment
        // (albums store one ChannelMessage per attachment with the full payload).
        $attachments = data_get($this->raw_payload, 'message.attachments')
            ?? data_get($this->raw_payload, 'attachments.data')
            ?? data_get($this->raw_payload, 'attachments');

        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $attachmentUrl = data_get($attachment, 'payload.url')
                    ?? data_get($attachment, 'image_data.url')
                    ?? data_get($attachment, 'file_url')
                    ?? data_get($attachment, 'video_data.url');

                if (! is_string($attachmentUrl) || $attachmentUrl !== $url) {
                    continue;
                }

                if (($attachment['type'] ?? null) === 'image' || data_get($attachment, 'image_data')) {
                    return true;
                }

                if (($attachment['type'] ?? null) === 'video' || data_get($attachment, 'video_data')) {
                    return false;
                }

                break;
            }
        }

        // Meta CDN/lookaside URLs rarely include a file extension
        return str_contains($url, 'fbcdn')
            || str_contains($url, 'fbsbx.com')
            || str_contains($url, 'lookaside');
    }
}
