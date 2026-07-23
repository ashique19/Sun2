<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductShareList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function home_exposes_website_json_ld_and_og_locale(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<meta property="og:locale" content="bn_BD">', false);
        $response->assertSee('"@type":"WebSite"', false);
        $response->assertSee('"@type":"Organization"', false);
    }

    #[Test]
    public function category_facet_urls_are_noindex_with_clean_canonical(): void
    {
        $category = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        $default = $this->get(route('category.show', $category));
        $default->assertOk();
        $default->assertSee('<meta name="robots" content="index, follow">', false);
        $default->assertSee('<link rel="canonical" href="'.route('category.show', $category).'">', false);

        $filtered = $this->get(route('category.show', $category).'?sort=price_asc');
        $filtered->assertOk();
        $filtered->assertSee('<meta name="robots" content="noindex, follow">', false);
        $filtered->assertSee('<link rel="canonical" href="'.route('category.show', $category).'">', false);
    }

    #[Test]
    public function product_share_lists_are_noindex(): void
    {
        $share = ProductShareList::query()->create([
            'token' => str_repeat('a', 32),
            'items' => [],
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->get(route('share.products', $share->token));

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    #[Test]
    public function unpublished_products_are_not_publicly_reachable(): void
    {
        $product = Product::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden-product',
            'sku' => 'H-1',
            'price' => 100,
            'purchase_price' => 40,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 0,
        ]);

        $this->get(route('product.show', $product))->assertNotFound();
    }
}
