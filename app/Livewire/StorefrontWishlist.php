<?php

namespace App\Livewire;

use App\Services\Storefront\CartService;
use App\Services\Storefront\WishlistService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Wishlist - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontWishlist extends Component
{
    public ?string $message = null;

    public function remove(int $productId, WishlistService $wishlist): void
    {
        $wishlist->toggle(auth()->id(), $productId);
        $this->message = __('storefront.wishlist_removed');
    }

    public function addToCart(int $productId, CartService $cart): void
    {
        $cart->add($productId, 1);
        $this->dispatch('cart-updated');
        $this->message = __('storefront.added_to_cart');
    }

    public function render(WishlistService $wishlist)
    {
        $items = auth()->user()
            ->wishlists()
            ->with(['product.images', 'product.category'])
            ->latest()
            ->get()
            ->map(fn ($row) => $row->product)
            ->filter(fn ($product) => $product && $product->is_published);

        return view('livewire.storefront-wishlist', [
            'items' => $items,
            'wishlistCount' => $wishlist->count(auth()->id()),
        ]);
    }
}
