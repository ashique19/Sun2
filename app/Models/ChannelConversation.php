<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class ChannelConversation extends Model
{
    public const CHANNEL_MESSENGER = 'messenger';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'draft_order_id' => 'integer',
            'last_read_by' => 'integer',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_read_at' => 'datetime',
            'messenger_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function draftOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'draft_order_id');
    }

    public function lastReadBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_read_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class)->orderBy('sent_at')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChannelMessage::class)->latestOfMany('id');
    }

    public function isWithinMessagingWindow(?\DateTimeInterface $now = null): bool
    {
        if (! $this->last_inbound_at) {
            return false;
        }

        $now = Carbon::parse($now ?? now());

        return $this->last_inbound_at->greaterThan($now->copy()->subHours(24));
    }

    public function isUnread(): bool
    {
        if (! $this->last_inbound_at) {
            return false;
        }

        return $this->last_read_at === null || $this->last_inbound_at->greaterThan($this->last_read_at);
    }

    /**
     * Whether Messenger Graph mark_seen still needs to catch up to the latest inbound.
     */
    public function needsMessengerSeenSync(): bool
    {
        if ($this->channel !== self::CHANNEL_MESSENGER) {
            return false;
        }

        if (! $this->last_inbound_at) {
            return false;
        }

        return $this->messenger_seen_at === null
            || $this->last_inbound_at->greaterThan($this->messenger_seen_at);
    }

    public function markRead(?int $userId = null): void
    {
        // Watermark at latest inbound so clock skew vs Facebook created_time cannot leave
        // the thread stuck unread (last_inbound_at > now()-based last_read_at).
        $this->forceFill([
            'last_read_at' => $this->last_inbound_at?->copy() ?? now(),
            'last_read_by' => $userId,
        ])->save();
    }
}
