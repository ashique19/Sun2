<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price'           => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'purchase_price'  => 'decimal:2',
            'commission'      => 'decimal:2',
            'max_discount'    => 'decimal:2',
            'is_published'    => 'boolean',
            'is_featured'     => 'boolean',
            'is_new'          => 'boolean',
            'is_best_seller'  => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Single image for cards/lists (primary preferred, else lowest sort_order). */
    public function listingImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(
            ['is_primary' => 'max', 'sort_order' => 'min'],
        );
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class)->where('status', 'approved');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeSearchTerm(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';
        $priceDigits = preg_replace('/[^\d.]/', '', $term);

        return $query->where(function (Builder $q) use ($like, $priceDigits) {
            $q->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like);

            if ($priceDigits !== '' && is_numeric($priceDigits)) {
                $price = (float) $priceDigits;

                $q->orWhere('price', $price)
                    ->orWhereRaw('CAST(price AS CHAR) LIKE ?', ['%'.$priceDigits.'%']);
            }
        });
    }

    public function scopeBrowse(Builder $query): Builder
    {
        return $query->published()->orderBy('display_order')->orderByDesc('id');
    }

    public function primaryImagePath(): ?string
    {
        if ($this->relationLoaded('listingImage')) {
            return $this->listingImage?->path;
        }

        if ($this->relationLoaded('images')) {
            $image = $this->images->firstWhere('is_primary', true) ?? $this->images->first();

            return $image?->path;
        }

        return $this->images()->where('is_primary', true)->value('path')
            ?? $this->images()->orderBy('sort_order')->value('path');
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }
}
