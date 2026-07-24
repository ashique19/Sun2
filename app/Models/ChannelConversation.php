<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class)->orderBy('sent_at')->orderBy('id');
    }

    public function isWithinMessagingWindow(?\DateTimeInterface $now = null): bool
    {
        if (! $this->last_inbound_at) {
            return false;
        }

        $now = \Illuminate\Support\Carbon::parse($now ?? now());

        return $this->last_inbound_at->greaterThan($now->copy()->subHours(24));
    }
}
