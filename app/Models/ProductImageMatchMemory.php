<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImageMatchMemory extends Model
{
    protected $fillable = [
        'hash',
        'hash_kind',
        'product_id',
        'source_channel_message_id',
        'created_by',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ChannelMessage::class, 'source_channel_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
