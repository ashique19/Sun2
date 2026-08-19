<?php

namespace App\Livewire;

use App\Models\SharedCart;
use App\Services\Admin\SharedCartService;
use App\Services\Storefront\CartService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PublicSharedCart extends Component
{
    public string $token = '';

    public ?SharedCart $sharedCart = null;

    public bool $expired = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->loadSharedCart();
    }

    public function proceedToCheckout(CartService $cart, SharedCartService $sharedCarts): mixed
    {
        $this->loadSharedCart();

        if ($this->expired || ! $this->sharedCart) {
            return null;
        }

        $items = $sharedCarts->sessionItems($this->sharedCart);

        if ($items === []) {
            return null;
        }

        $cart->replaceItems($items);

        return $this->redirect(route('checkout'), navigate: true);
    }

    public function render(SharedCartService $sharedCarts)
    {
        $lines = $this->sharedCart && ! $this->expired
            ? $sharedCarts->previewLines($this->sharedCart)
            : collect();

        return view('livewire.public-shared-cart', [
            'lines' => $lines,
            'subtotal' => (float) $lines->sum('line_total'),
            'itemCount' => (int) $lines->sum('quantity'),
        ])->title($this->expired ? __('storefront.shared_cart_expired_title') : __('storefront.shared_cart_title'));
    }

    private function loadSharedCart(): void
    {
        $this->sharedCart = SharedCart::query()->where('token', $this->token)->first();
        $this->expired = ! $this->sharedCart || $this->sharedCart->isExpired();
    }
}
