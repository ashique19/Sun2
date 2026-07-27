<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostPublication extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public const CHANNEL_FACEBOOK = 'facebook';
    public const CHANNEL_INSTAGRAM = 'instagram';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}

