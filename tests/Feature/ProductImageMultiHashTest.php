<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageMultiHashTest extends TestCase
{
    use RefreshDatabase;

    private function stripePng(int $size = 200): string
    {
        $image = imagecreatetruecolor($size, $size);
        $a = imagecolorallocate($image, 200, 40, 60);
        $b = imagecolorallocate($image, 40, 90, 200);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                imagesetpixel($image, $x, $y, (((int) ($x / 10)) % 2) === 0 ? $a : $b);
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    #[Test]
    public function store_hash_persists_full_and_variant_hashes(): void
    {
        $bytes = $this->stripePng();
        $relativeDir = 'img/products/multi-hash';
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        $relativePath = $relativeDir.'/catalog-'.uniqid().'.png';
        file_put_contents(public_path($relativePath), $bytes);

        $product = Product::query()->create([
            'name' => 'Multi Hash Ring',
            'slug' => 'multi-hash-ring-'.uniqid(),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativePath,
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $hasher = app(ProductImageHashService::class);
        $full = $hasher->storeHash($image);

        $image->refresh();

        $this->assertNotNull($full);
        $this->assertSame($full, $image->perceptual_hash);
        $this->assertIsArray($image->perceptual_hashes);
        $this->assertGreaterThanOrEqual(3, count($image->perceptual_hashes));
        $this->assertSame('full', $image->perceptual_hashes[0]['strategy']);
        $this->assertSame($full, $image->perceptual_hashes[0]['hash']);

        $strategies = array_column($image->perceptual_hashes, 'strategy');
        $this->assertContains('center_0.7', $strategies);
        $this->assertContains('center_0.5', $strategies);

        @unlink(public_path($relativePath));
    }

    #[Test]
    public function screenshot_matches_catalog_center_variant_without_query_crop(): void
    {
        $hasher = app(ProductImageHashService::class);
        $catalogBytes = $this->stripePng(200);

        $product = Product::query()->create([
            'name' => 'Variant Match Earring',
            'slug' => 'variant-match-earring-'.uniqid(),
            'price' => 1800,
            'purchase_price' => 700,
            'stock_quantity' => 3,
            'is_published' => true,
        ]);

        $variants = $hasher->catalogHashVariantsFromBinary($catalogBytes);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/missing/variant-match.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $variants[0]['hash'],
            'perceptual_hashes' => $variants,
        ]);

        // Query is a center crop of the catalog (as if screenshot chrome was already removed poorly
        // but catalog center variants still match).
        $queryHash = $hasher->hashBinary($catalogBytes, 0.5);
        $matches = $hasher->findTopMatches($queryHash, 1, ProductImageHashService::AUTO_MATCH_PERCENT);

        $this->assertCount(1, $matches);
        $this->assertSame($product->id, $matches[0]['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageHashService::AUTO_MATCH_PERCENT, $matches[0]['match_percent']);
    }
}
