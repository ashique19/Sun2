<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductVideoAdTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Jewellery',
            'slug' => 'jewellery-video',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Video Ad Ring',
            'slug' => 'video-ad-ring',
            'price' => 1500,
            'is_published' => true,
            'description' => '<p>Product for video ad placement.</p>',
        ]);
    }

    #[Test]
    public function product_page_includes_hilltop_video_loader_when_enabled(): void
    {
        config([
            'ads.placements.product_video' => true,
            'ads.product_video_src' => '//quarrelsomebitter.com/bXXfVVs.dyGKlU0AYlWhcN/xe/mr9vuoZwUGlBkpPiTscxz/NCzaEn0MMjDgk/teNNz/MY3BM_TNQvxHMuwM',
        ]);

        $product = $this->publishedProduct();

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertSee('storefront-product-video-ad', false);
        $response->assertSee('data-product-video-ad', false);
        $response->assertSee('quarrelsomebitter.com', false);
        $response->assertSee('no-referrer-when-downgrade', false);
    }

    #[Test]
    public function product_page_omits_video_loader_when_disabled(): void
    {
        config(['ads.placements.product_video' => false]);

        $product = $this->publishedProduct();

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertDontSee('storefront-product-video-ad', false);
        $response->assertDontSee('quarrelsomebitter.com', false);
    }

    #[Test]
    public function home_page_never_includes_product_video_loader(): void
    {
        config([
            'ads.placements.product_video' => true,
            'ads.product_video_src' => '//quarrelsomebitter.com/video-test',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('storefront-product-video-ad', false)
            ->assertDontSee('quarrelsomebitter.com', false);
    }
}
