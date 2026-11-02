<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxQuickReplyExample extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel_message_id' => 'integer',
            'tagged_by' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChannelMessage::class, 'channel_message_id');
    }

    public function taggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tagged_by');
    }
}
