<?php

namespace App\Livewire;

use App\Services\Storefront\CartService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Shopping Cart - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontCart extends Component
{
    public function updateQuantity(int $productId, int $quantity, CartService $cart): void
    {
        $cart->update($productId, $quantity);
        $this->dispatch('cart-updated');
    }

    public function remove(int $productId, CartService $cart): void
    {
        $cart->remove($productId);
        $this->dispatch('cart-updated');
    }

    public function render(CartService $cart)
    {
        return view('livewire.storefront-cart', [
            'lines' => $cart->lines(),
            'subtotal' => $cart->subtotal(),
        ]);
    }
}
