<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCart;
use App\Livewire\StorefrontProduct;
use App\Models\Product;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductAddRedirectsToCartTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Redirect Ring',
            'slug' => 'redirect-ring',
            'sku' => 'RR-1',
            'price' => 500,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 1,
        ]);
    }

    #[Test]
    public function adding_product_to_cart_redirects_to_cart_page(): void
    {
        $product = $this->product();

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->call('addToCart')
            ->assertRedirect(route('cart'));

        $this->assertSame(1, app(CartService::class)->count());
        $this->assertSame(1, app(CartService::class)->items()[$product->id] ?? 0);
    }

    #[Test]
    public function cart_page_shows_buy_more_products_back_link(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id, 1);

        Livewire::test(StorefrontCart::class)
            ->assertSee('আরো প্রোডাক্ট কিনুন', false)
            ->assertSee(route('shop'), false);
    }

    #[Test]
    public function empty_cart_also_offers_buy_more_products(): void
    {
        Livewire::test(StorefrontCart::class)
            ->assertSee('আরো প্রোডাক্ট কিনুন', false)
            ->assertSee(route('shop'), false);
    }
}
