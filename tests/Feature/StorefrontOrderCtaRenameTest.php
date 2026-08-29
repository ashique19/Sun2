<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCart;
use App\Livewire\StorefrontCheckout;
use App\Livewire\StorefrontProduct;
use App\Models\Product;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontOrderCtaRenameTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Test Ring',
            'slug' => 'test-ring-cta',
            'sku' => 'TR-CTA-1',
            'price' => 500,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 1,
        ]);
    }

    #[Test]
    public function product_page_uses_order_ctas_instead_of_cart_wording(): void
    {
        $product = $this->product();

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertSee('অর্ডার করুন', false)
            ->assertDontSee('কার্টে রাখুন', false)
            ->call('addToCart')
            ->assertRedirect(route('cart'));
    }

    #[Test]
    public function cart_page_uses_order_list_wording(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id, 1);

        Livewire::test(StorefrontCart::class)
            ->assertSee('অর্ডার লিস্ট', false)
            ->assertSee('অর্ডার কন্ফার্ম করুন', false)
            ->assertDontSee('শপিং কার্ট', false);
    }

    #[Test]
    public function checkout_page_uses_confirm_order_cta(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id, 1);

        Livewire::test(StorefrontCheckout::class)
            ->assertSee('অর্ডার কন্ফার্ম করুন', false)
            ->assertDontSee('কোড পাঠান ও এগোন', false);
    }
}
