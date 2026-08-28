<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageEmbeddingService;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageHashIndexBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_command_selects_rows_missing_dct_or_embedding_even_when_dhash_exists(): void
    {
        $hasher = app(ProductImageHashService::class);

        $product = Product::query()->create([
            'name' => 'Legacy Hash Product',
            'slug' => 'legacy-hash-'.uniqid(),
            'price' => 1500,
            'purchase_price' => 600,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $absolute = $absoluteDir.'/catalog.jpg';
        $image = imagecreatetruecolor(120, 120);
        $color = imagecolorallocate($image, 180, 60, 90);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        $legacyHash = $hasher->hashFile($absolute);

        $row = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $legacyHash,
            'perceptual_hashes' => [
                ['strategy' => 'full', 'hash' => $legacyHash],
            ],
            'dct_hash' => null,
            'embedding_vector' => null,
        ]);

        Artisan::call('products:index-image-hashes', ['--limit' => 1]);

        $row->refresh();

        $this->assertNotNull($row->dct_hash);
        $this->assertIsArray($row->embedding_vector);
        $this->assertCount(ProductImageEmbeddingService::DIMENSIONS, $row->embedding_vector);
        $this->assertGreaterThan(1, count($row->perceptual_hashes ?? []));
    }
}
