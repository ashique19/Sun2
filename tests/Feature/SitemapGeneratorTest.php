<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Sitemap\SitemapRebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['sitemap.auto_rebuild_debounce_seconds' => 0]);
    }

    #[Test]
    public function sitemap_captures_home_active_categories_pages_and_published_products_with_images(): void
    {
        $activeCategory = Category::query()->create([
            'name' => 'Necklaces',
            'slug' => 'necklaces',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden-category',
            'is_active' => false,
            'is_homepage' => false,
            'display_order' => 2,
        ]);

        $page = Page::query()->create([
            'name' => 'About Us',
            'slug' => 'about-us',
            'details' => '<p>About</p>',
            'meta_tag_title' => 'About Us - Sundoritoma',
            'meta_tag_description' => 'About Sundoritoma',
        ]);

        $published = Product::query()->create([
            'name' => 'Necklace, earring set',
            'slug' => 'necklace-earring-set',
            'sku' => 'NES-1',
            'price' => 1500,
            'purchase_price' => 600,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            'category_id' => $activeCategory->id,
        ]);

        ProductImage::query()->create([
            'product_id' => $published->id,
            'path' => 'img/thumb/necklace-primary_md.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'alt' => 'Primary',
        ]);

        Product::query()->create([
            'name' => 'Draft product',
            'slug' => 'draft-product',
            'sku' => 'DR-1',
            'price' => 100,
            'purchase_price' => 40,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 0,
            'category_id' => $activeCategory->id,
        ]);

        /** @var SitemapRebuildService $sitemaps */
        $sitemaps = app(SitemapRebuildService::class);
        $run = $sitemaps->runToCompletion($sitemaps->start('test', force: true));

        $this->assertSame('completed', $run->status);
        // home + 1 active category + 1 page + 1 published product
        $this->assertSame(4, (int) $run->urls_written);

        $index = $this->get('/sitemap.xml');
        $index->assertOk();
        $index->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $index->assertSee('/sitemaps/pages.xml', false);
        $index->assertSee('/sitemaps/products-1.xml', false);

        $pages = $this->get('/sitemaps/pages.xml');
        $pages->assertOk();
        $pagesXml = $pages->getContent();

        $this->assertStringContainsString(route('home'), $pagesXml);
        $this->assertStringContainsString(route('category.show', $activeCategory), $pagesXml);
        $this->assertStringContainsString(route('page.show', $page), $pagesXml);
        $this->assertStringNotContainsString('hidden-category', $pagesXml);

        $products = $this->get('/sitemaps/products-1.xml');
        $products->assertOk();
        $productsXml = $products->getContent();

        $this->assertStringContainsString(route('product.show', $published), $productsXml);
        $this->assertStringNotContainsString('draft-product', $productsXml);
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $productsXml);
        $this->assertStringContainsString('<image:image>', $productsXml);
        $this->assertStringContainsString('necklace-primary', $productsXml);
        $this->assertStringContainsString('<image:title>Necklace, earring set</image:title>', $productsXml);
    }

    #[Test]
    public function robots_txt_points_at_sitemap_and_blocks_private_paths(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSee('Sitemap: '.url('/sitemap.xml'), false);
        $response->assertSee('Disallow: /admin', false);
        $response->assertSee('Disallow: /cart', false);
        $response->assertSee('Disallow: /search', false);
        $response->assertSee('Disallow: /share/', false);
    }
}
