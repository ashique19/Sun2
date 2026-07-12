<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Storefront\CartService;
use App\Services\Storefront\WishlistService;
use App\Support\Seo;
use App\Support\StorefrontAssets;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontProduct extends Component
{
    public Product $product;

    public int $quantity = 1;

    public int $activeImage = 0;

    public ?string $addedMessage = null;

    public bool $isWishlisted = false;

    public int $reviewRating = 5;

    public string $reviewTitle = '';

    public string $reviewBody = '';

    public ?string $reviewMessage = null;

    public ?string $reviewError = null;

    public function mount(Product $product, WishlistService $wishlist): void
    {
        abort_unless($product->is_published, 404);

        $this->product = $product->load(['images', 'category', 'approvedReviews.user']);

        if (auth()->check()) {
            $this->isWishlisted = $wishlist->has(auth()->id(), $product->id);
        }
    }

    public function selectImage(int $index): void
    {
        $this->activeImage = $index;
    }

    public function addToCart(CartService $cart): void
    {
        if (! $this->product->isInStock()) {
            return;
        }

        $cart->add($this->product->id, $this->quantity);
        $this->addedMessage = 'Added to cart';
        $this->dispatch('cart-updated');
    }

    public function toggleWishlist(WishlistService $wishlist): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->isWishlisted = $wishlist->toggle(auth()->id(), $this->product->id);
        $this->dispatch('wishlist-updated');
    }

    public function submitReview(): void
    {
        $this->reviewMessage = null;
        $this->reviewError = null;

        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->validate([
            'reviewRating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviewTitle' => ['nullable', 'string', 'max:120'],
            'reviewBody' => ['required', 'string', 'max:2000'],
        ]);

        $exists = ProductReview::query()
            ->where('product_id', $this->product->id)
            ->where('user_id', auth()->id())
            ->exists();

        if ($exists) {
            $this->reviewError = 'You have already reviewed this product.';

            return;
        }

        ProductReview::query()->create([
            'product_id' => $this->product->id,
            'user_id' => auth()->id(),
            'rating' => $this->reviewRating,
            'title' => $this->reviewTitle ?: null,
            'body' => $this->reviewBody,
            'status' => 'pending',
        ]);

        $this->reviewTitle = '';
        $this->reviewBody = '';
        $this->reviewRating = 5;
        $this->reviewMessage = 'Thank you! Your review will appear after approval.';
    }

    public function title(): string
    {
        return $this->product->meta_title
            ?: ($this->product->name.' - Sundoritoma');
    }

    public function render()
    {
        $image = StorefrontAssets::mediumUrl($this->product->primaryImagePath())
            ?? StorefrontAssets::url($this->product->primaryImagePath());

        return view('livewire.storefront-product')
            ->title($this->title())
            ->layoutData([
                'seoDescription' => Seo::description(
                    $this->product->meta_description ?: $this->product->description,
                    $this->product->name.' — high-quality handmade jewellery from Sundoritoma. Home delivery all over Bangladesh.',
                ),
                'seoCanonical' => route('product.show', $this->product),
                'seoImage' => $image,
                'seoType' => 'product',
            ]);
    }
}
