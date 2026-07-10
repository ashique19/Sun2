<?php

namespace App\Models;

use App\Support\StorefrontAssets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'to_be_returned' => 'boolean',
            'return_received' => 'boolean',
            'returned_quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageUrl(): ?string
    {
        if ($this->product_image) {
            return StorefrontAssets::url($this->product_image);
        }

        if ($this->relationLoaded('product') && $this->product) {
            return StorefrontAssets::url($this->product->primaryImagePath());
        }

        return null;
    }

    public function previewImageUrl(): ?string
    {
        if ($this->relationLoaded('product') && $this->product) {
            $productImage = StorefrontAssets::url($this->product->primaryImagePath());

            if ($productImage) {
                return StorefrontAssets::largestAvailableUrl($productImage);
            }
        }

        $url = $this->imageUrl();

        return $url ? StorefrontAssets::largestAvailableUrl($url) : null;
    }

    public function displayName(): string
    {
        if ($this->relationLoaded('product') && $this->product?->name) {
            return $this->product->name;
        }

        return $this->name;
    }

    public function storefrontUrl(): ?string
    {
        if (! $this->product_id) {
            return null;
        }

        $product = $this->relationLoaded('product') ? $this->product : null;

        if (! $product?->slug) {
            return null;
        }

        return route('product.show', $product);
    }
}
