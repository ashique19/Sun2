<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductShareMetaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function product_page_exposes_og_title_with_price_and_primary_image(): void
    {
        $product = Product::query()->create([
            'name' => 'Necklace, earring set',
            'slug' => 'necklace-earring-set',
            'sku' => 'NES-1',
            'price' => 1500,
            'purchase_price' => 600,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            'meta_title' => 'SEO Title Should Not Override Share Preview',
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/necklace-secondary_md.jpg',
            'is_primary' => false,
            'sort_order' => 1,
            'alt' => 'Secondary',
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/necklace-primary_md.jpg',
            'is_primary' => true,
            'sort_order' => 2,
            'alt' => 'Primary',
        ]);

        $response = $this->get(route('product.show', $product));

        $response->assertOk();

        $shareTitle = Seo::productShareTitle($product);
        $this->assertSame('৳ 1,500 (Necklace, earring set)', $shareTitle);

        $response->assertSee('<meta property="og:title" content="'.$shareTitle.'">', false);
        $response->assertSee('<meta name="twitter:title" content="'.$shareTitle.'">', false);
        $response->assertSee('<meta property="og:type" content="product">', false);
        $response->assertSee('<meta property="product:price:amount" content="1500.00">', false);
        $response->assertSee('<meta property="product:price:currency" content="BDT">', false);

        // Browser/SEO title stays separate from Messenger/WhatsApp preview title.
        $response->assertSee('<title>SEO Title Should Not Override Share Preview</title>', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/property="og:image"\s+content="[^"]*necklace-primary/',
            $html,
        );
    }
}
