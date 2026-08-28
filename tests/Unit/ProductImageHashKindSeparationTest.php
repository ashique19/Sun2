<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageHashKindSeparationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dhash_queries_do_not_compare_against_catalog_dct_hashes(): void
    {
        $hasher = app(ProductImageHashService::class);

        $product = Product::query()->create([
            'name' => 'DCT Only Product',
            'slug' => 'dct-only-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 1,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/'.$product->id.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => '0000000000000000',
            'dct_hash' => 'ffffffffffffffff',
        ]);

        $matches = $hasher->findTopMatches('ffffffffffffffff', 1, 80, 'dhash');

        $this->assertSame([], $matches);
    }

    #[Test]
    public function dct_queries_only_compare_against_catalog_dct_hashes(): void
    {
        $hasher = app(ProductImageHashService::class);

        $product = Product::query()->create([
            'name' => 'Mixed Hash Product',
            'slug' => 'mixed-hash-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 1,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/'.$product->id.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => 'aaaaaaaaaaaaaaaa',
            'dct_hash' => 'bbbbbbbbbbbbbbbb',
        ]);

        $dhashMatches = $hasher->findTopMatches('bbbbbbbbbbbbbbbb', 1, 80, 'dhash');
        $this->assertSame([], $dhashMatches);

        $dctMatches = $hasher->findTopMatches('bbbbbbbbbbbbbbbb', 1, 80, 'dct');
        $this->assertCount(1, $dctMatches);
        $this->assertSame($product->id, $dctMatches[0]['product_id']);
        $this->assertSame(100.0, $dctMatches[0]['match_percent']);
    }
}
