<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostProduct extends Model
{
    protected $guarded = [];

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

