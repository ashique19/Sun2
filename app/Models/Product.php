<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'priced_image_layout' => 'array',
            'purchase_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'commission' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_best_seller' => 'boolean',
        ];
    }

    /** Common Bangla price units for stamps / catalog. */
    public const PRICE_UNIT_PRESETS = [
        'পিস',
        'জোড়া',
        'সেট',
    ];

    /**
     * Unit label shown on priced images (e.g. পিস, জোড়া, সেট).
     */
    public function priceUnitLabel(): string
    {
        $unit = trim((string) ($this->price_unit ?? ''));

        return $unit !== '' ? $unit : 'পিস';
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class, 'product_materials')
            ->withPivot(['id', 'quantity', 'is_primary'])
            ->withTimestamps()
            ->orderByDesc('product_materials.is_primary')
            ->orderBy('materials.name');
    }

    public function costHeads(): HasMany
    {
        return $this->hasMany(ProductCostHead::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Total unit cost for COGS (falls back to main purchase_price when unset / ~৳0).
     */
    public function effectiveUnitCost(): float
    {
        if ($this->unit_cost !== null && $this->unit_cost !== '' && (float) $this->unit_cost >= 0.01) {
            return round((float) $this->unit_cost, 2);
        }

        return round((float) $this->purchase_price, 2);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Single image for cards/lists (primary preferred, else lowest sort_order). Storefront-visible only. */
    public function listingImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(
            ['is_primary' => 'max', 'sort_order' => 'min'],
            function ($query) {
                $query->where('is_admin_only', false);
            },
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

    public function scopeSearchTerm(Builder $query, string $term, bool $includePrice = true): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';
        $priceDigits = preg_replace('/[^\d.]/', '', $term);

        return $query->where(function (Builder $q) use ($like, $priceDigits, $includePrice) {
            $q->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like);

            if ($includePrice && $priceDigits !== '' && is_numeric($priceDigits)) {
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
            $visible = $this->images->where('is_admin_only', false);
            $image = $visible->firstWhere('is_primary', true) ?? $visible->first();

            return $image?->path;
        }

        return $this->images()->where('is_admin_only', false)->where('is_primary', true)->value('path')
            ?? $this->images()->where('is_admin_only', false)->orderBy('sort_order')->value('path');
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }
}
