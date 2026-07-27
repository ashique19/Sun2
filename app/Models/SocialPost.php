<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    protected $guarded = [];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public const IMAGE_SOURCE_THUMB = 'thumb';
    public const IMAGE_SOURCE_PRICED = 'priced';

    public const LAYOUT_ALBUM = 'album';
    public const LAYOUT_COLLAGE = 'collage';

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'social_post_products')
            ->withPivot(['sort_order', 'thumb_snapshot_path', 'priced_snapshot_path'])
            ->orderBy('social_post_products.sort_order')
            ->orderByDesc('social_post_products.id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPostPublication::class)->orderByDesc('id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function facebookPublication(): ?SocialPostPublication
    {
        return $this->publications()
            ->where('channel', SocialPostPublication::CHANNEL_FACEBOOK)
            ->where('status', SocialPostPublication::STATUS_SUCCESS)
            ->orderByDesc('published_at')
            ->first();
    }
}

