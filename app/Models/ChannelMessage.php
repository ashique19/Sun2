<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel_conversation_id' => 'integer',
            'raw_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'channel_conversation_id');
    }

    public function hasMedia(): bool
    {
        return filled($this->media_url);
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

        // Messenger webhook shape
        $attachmentType = data_get($this->raw_payload, 'message.attachments.0.type')
            ?? data_get($this->raw_payload, 'attachments.0.type');
        if ($attachmentType === 'image') {
            return true;
        }

        // WhatsApp Cloud API message shape stores type at the root of raw_payload
        if (data_get($this->raw_payload, 'type') === 'image') {
            return true;
        }

        // Meta CDN/lookaside URLs rarely include a file extension
        return str_contains($url, 'fbcdn')
            || str_contains($url, 'fbsbx.com')
            || str_contains($url, 'lookaside');
    }
}
