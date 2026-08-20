<?php

namespace Tests\Feature;

use App\Livewire\StorefrontProduct;
use App\Models\Category;
use App\Models\Product;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductPriceUnitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function product_page_shows_price_with_unit(): void
    {
        $product = Product::query()->create([
            'name' => 'Pearl Set',
            'slug' => 'pearl-set-unit',
            'price' => 500,
            'compare_at_price' => 1200,
            'price_unit' => 'সেট',
            'is_published' => true,
        ]);

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertSeeHtml('&#2547; ১,২০০')
            ->assertSeeHtml('line-through')
            ->assertSeeHtml('&#2547; ৫০০')
            ->assertSeeHtml('/সেট');
    }

    #[Test]
    public function product_card_shows_default_piece_unit(): void
    {
        $category = Category::query()->create([
            'name' => 'Jewellery',
            'slug' => 'jewellery-unit',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Plain Ring',
            'slug' => 'plain-ring-unit',
            'price' => 650,
            'price_unit' => 'পিস',
            'category_id' => $category->id,
            'is_published' => true,
        ]);

        $html = view('components.storefront.product-card', ['product' => $product])->render();

        $this->assertStringContainsString('&#2547; ৬৫০', $html);
        $this->assertStringContainsString('/পিস', $html);
    }

    #[Test]
    public function share_title_includes_price_unit(): void
    {
        $product = Product::query()->create([
            'name' => 'Jhumka Pair',
            'slug' => 'jhumka-pair-unit',
            'price' => 900,
            'price_unit' => 'জোড়া',
            'is_published' => true,
        ]);

        $this->assertSame('৳ ৯০০/জোড়া (Jhumka Pair)', Seo::productShareTitle($product));
    }
}
