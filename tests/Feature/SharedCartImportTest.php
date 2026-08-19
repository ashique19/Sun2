<?php

namespace Tests\Feature;

use App\Livewire\PublicSharedCart;
use App\Models\Product;
use App\Models\SharedCart;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SharedCartImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_can_preview_shared_cart_and_proceed_to_checkout(): void
    {
        $productA = Product::query()->create([
            'name' => 'Cart Product A',
            'slug' => 'cart-product-a',
            'sku' => 'CPA-1',
            'price' => 500,
            'is_published' => true,
            'display_order' => 1,
        ]);

        $productB = Product::query()->create([
            'name' => 'Cart Product B',
            'slug' => 'cart-product-b',
            'sku' => 'CPB-1',
            'price' => 750,
            'is_published' => true,
            'display_order' => 2,
        ]);

        $share = SharedCart::query()->create([
            'token' => str_repeat('b', 48),
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('share.cart', $share->token))
            ->assertOk()
            ->assertSee('Cart Product A')
            ->assertSee('Cart Product B')
            ->assertSee(__('storefront.proceed_checkout'))
            ->assertSee('1,750', false);

        app(CartService::class)->clear();

        Livewire::test(PublicSharedCart::class, ['token' => $share->token])
            ->call('proceedToCheckout')
            ->assertRedirect(route('checkout'));

        $cart = app(CartService::class);

        $this->assertSame([
            $productA->id => 2,
            $productB->id => 1,
        ], $cart->items());
        $this->assertSame(1750.0, $cart->subtotal());
    }

    #[Test]
    public function expired_shared_cart_shows_expired_message(): void
    {
        $share = SharedCart::query()->create([
            'token' => str_repeat('c', 48),
            'items' => [],
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('share.cart', $share->token))
            ->assertOk()
            ->assertSee(__('storefront.shared_cart_expired_title'));
    }

    #[Test]
    public function shared_carts_are_noindex(): void
    {
        $share = SharedCart::query()->create([
            'token' => str_repeat('d', 48),
            'items' => [],
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('share.cart', $share->token))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
