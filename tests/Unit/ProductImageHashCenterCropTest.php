<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageHashCenterCropTest extends TestCase
{
    use RefreshDatabase;

    private function paintStripes(\GdImage $image, int $x0, int $y0, int $width, int $height): void
    {
        $a = imagecolorallocate($image, 200, 40, 60);
        $b = imagecolorallocate($image, 40, 90, 200);

        for ($y = $y0; $y < $y0 + $height; $y++) {
            for ($x = $x0; $x < $x0 + $width; $x++) {
                imagesetpixel($image, $x, $y, (((int) (($x - $x0) / 10)) % 2) === 0 ? $a : $b);
            }
        }
    }

    private function pngBytes(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @return array{0: string, 1: string} catalog png bytes, screenshot png bytes
     */
    private function catalogAndScreenshotBytes(): array
    {
        $catalog = imagecreatetruecolor(200, 200);
        $this->paintStripes($catalog, 0, 0, 200, 200);

        $screenshot = imagecreatetruecolor(400, 400);
        $chrome = imagecolorallocate($screenshot, 30, 30, 30);
        imagefill($screenshot, 0, 0, $chrome);
        imagecopy($screenshot, $catalog, 100, 100, 0, 0, 200, 200);

        $catalogBytes = $this->pngBytes($catalog);
        $screenshotBytes = $this->pngBytes($screenshot);

        return [$catalogBytes, $screenshotBytes];
    }

    #[Test]
    public function query_center_crop_matches_full_catalog_when_chrome_surrounds_product(): void
    {
        $hasher = app(ProductImageHashService::class);
        [$catalogBytes, $screenshotBytes] = $this->catalogAndScreenshotBytes();

        $fullPercent = $hasher->matchPercent(
            $hasher->hashBinary($catalogBytes),
            $hasher->hashBinary($screenshotBytes),
        );

        // Center 50% of the screenshot is the pasted catalog product.
        $centerVsFull = $hasher->matchPercent(
            $hasher->hashBinary($catalogBytes),
            $hasher->hashBinary($screenshotBytes, 0.5),
        );

        $this->assertLessThan(ProductImageHashService::AUTO_MATCH_PERCENT, $fullPercent);
        $this->assertGreaterThanOrEqual(ProductImageHashService::AUTO_MATCH_PERCENT, $centerVsFull);
    }

    #[Test]
    public function auto_match_falls_back_to_center_crop_for_screenshots(): void
    {
        $hasher = app(ProductImageHashService::class);
        [$catalogBytes, $screenshotBytes] = $this->catalogAndScreenshotBytes();

        $relativeDir = 'img/products/crop-fallback';
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        file_put_contents($absoluteDir.'/catalog.png', $catalogBytes);

        $product = Product::query()->create([
            'name' => 'Crop Fallback Ring',
            'slug' => 'crop-fallback-ring-'.uniqid(),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 3,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $fullMatches = $hasher->findTopMatches(
            $hasher->hashBinary($screenshotBytes),
            1,
            ProductImageHashService::AUTO_MATCH_PERCENT,
        );
        $this->assertSame([], $fullMatches, 'full-frame should miss auto threshold');

        $match = $hasher->findBestAutoMatchFromBinary($screenshotBytes);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageHashService::AUTO_MATCH_PERCENT, $match['match_percent']);
        $this->assertTrue(
            str_contains($match['strategy'], 'trim')
            || str_contains($match['strategy'], 'center'),
            'Expected trim or center-crop strategy, got: '.$match['strategy'],
        );
        $this->assertStringContainsString('catalog_full', $match['strategy']);
    }

    #[Test]
    public function auto_match_center_fallback_uses_stored_hashes_without_rereading_catalog_files(): void
    {
        $hasher = app(ProductImageHashService::class);
        [$catalogBytes, $screenshotBytes] = $this->catalogAndScreenshotBytes();

        $product = Product::query()->create([
            'name' => 'Stored Hash Only',
            'slug' => 'stored-hash-only-'.uniqid(),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 1,
            'is_published' => true,
        ]);

        // Path points at a missing file — Plan B must not re-decode catalog images.
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/missing/does-not-exist.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $match = $hasher->findBestAutoMatchFromBinary($screenshotBytes);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertStringContainsString('query_', $match['strategy']);
    }

    #[Test]
    public function chrome_trim_matches_asymmetric_phone_screenshot(): void
    {
        $hasher = app(ProductImageHashService::class);

        $catalog = imagecreatetruecolor(180, 180);
        $this->paintStripes($catalog, 0, 0, 180, 180);
        $catalogBytes = $this->pngBytes($catalog);

        $screenshot = imagecreatetruecolor(480, 640);
        $chrome = imagecolorallocate($screenshot, 12, 12, 16);
        imagefill($screenshot, 0, 0, $chrome);

        $catalogImage = imagecreatefromstring($catalogBytes);
        imagecopy($screenshot, $catalogImage, 36, 132, 0, 0, 180, 180);
        imagedestroy($catalogImage);

        ob_start();
        imagepng($screenshot);
        $screenshotBytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        $product = Product::query()->create([
            'name' => 'Trim Match Earring',
            'slug' => 'trim-match-earring-'.uniqid(),
            'price' => 1800,
            'purchase_price' => 700,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/missing/trim-match.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $fullPercent = $hasher->matchPercent(
            $hasher->hashBinary($catalogBytes),
            $hasher->hashBinary($screenshotBytes),
        );
        $this->assertLessThan(ProductImageHashService::AUTO_MATCH_PERCENT, $fullPercent);

        $match = $hasher->findBestAutoMatchFromBinary($screenshotBytes);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageHashService::AUTO_MATCH_PERCENT, $match['match_percent']);
        $this->assertStringContainsString('trim', $match['strategy']);
    }

    #[Test]
    public function find_top_matches_from_binary_uses_trim_for_screenshots(): void
    {
        $hasher = app(ProductImageHashService::class);
        [$catalogBytes, $screenshotBytes] = $this->catalogAndScreenshotBytes();

        $product = Product::query()->create([
            'name' => 'Top Match From Binary',
            'slug' => 'top-match-from-binary-'.uniqid(),
            'price' => 900,
            'purchase_price' => 300,
            'stock_quantity' => 1,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/missing/top-match.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $matches = $hasher->findTopMatchesFromBinary(
            $screenshotBytes,
            1,
            ProductImageHashService::AUTO_MATCH_PERCENT,
        );

        $this->assertCount(1, $matches);
        $this->assertSame($product->id, $matches[0]['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageHashService::AUTO_MATCH_PERCENT, $matches[0]['match_percent']);
    }
}
