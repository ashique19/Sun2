<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageEmbeddingService;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private function solidJpeg(int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(48, 48);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, 47, 47, $color);
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    #[Test]
    public function embed_binary_returns_fixed_dimension_vector(): void
    {
        $service = app(ProductImageEmbeddingService::class);
        $vector = $service->embedBinary($this->solidJpeg(220, 30, 40));

        $this->assertCount(ProductImageEmbeddingService::DIMENSIONS, $vector);
        $sumSq = 0.0;
        foreach ($vector as $v) {
            $sumSq += $v * $v;
        }
        $this->assertEqualsWithDelta(1.0, sqrt($sumSq), 0.01);
    }

    #[Test]
    public function identical_images_auto_match_via_embedding(): void
    {
        $bytes = $this->solidJpeg(40, 160, 90);
        $service = app(ProductImageEmbeddingService::class);

        $product = Product::query()->create([
            'name' => 'Embed Ring',
            'slug' => 'embed-ring-'.uniqid(),
            'sku' => 'EMB'.random_int(100, 999),
            'price' => 900,
            'purchase_price' => 400,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/embed.jpg',
            'alt' => $product->name,
            'sort_order' => 0,
            'embedding_vector' => $service->embedBinary($bytes),
        ]);

        $match = $service->findBestAutoMatchFromBinary($bytes);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertSame('embedding', $match['strategy']);
        $this->assertGreaterThanOrEqual(92.0, $match['match_percent']);
    }

    #[Test]
    public function chrome_framed_photo_matches_from_stored_embedding_when_hashes_are_absent(): void
    {
        $service = app(ProductImageEmbeddingService::class);

        $catalog = imagecreatetruecolor(120, 120);
        $red = imagecolorallocate($catalog, 210, 40, 50);
        $blue = imagecolorallocate($catalog, 30, 90, 200);
        for ($y = 0; $y < 120; $y++) {
            for ($x = 0; $x < 120; $x++) {
                imagesetpixel($catalog, $x, $y, ((int) ($x / 8) % 2) === 0 ? $red : $blue);
            }
        }
        ob_start();
        imagejpeg($catalog, null, 90);
        $catalogBytes = (string) ob_get_clean();

        $screenshot = imagecreatetruecolor(360, 480);
        $chrome = imagecolorallocate($screenshot, 16, 16, 18);
        imagefill($screenshot, 0, 0, $chrome);
        imagecopy($screenshot, $catalog, 120, 160, 0, 0, 120, 120);
        imagedestroy($catalog);
        ob_start();
        imagejpeg($screenshot, null, 85);
        $screenshotBytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        $product = Product::query()->create([
            'name' => 'Framed Embed Saree',
            'slug' => 'framed-embed-'.uniqid(),
            'sku' => 'FRM'.random_int(100, 999),
            'price' => 2200,
            'purchase_price' => 900,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/framed-embed.jpg',
            'alt' => $product->name,
            'sort_order' => 0,
            'embedding_vector' => $service->embedBinary($catalogBytes),
        ]);

        $full = $service->findBestAutoMatchFromBinary($screenshotBytes);
        $match = $full;

        if ($match === null) {
            foreach (app(ProductImageHashService::class)->contentCropsForEmbedding($screenshotBytes) as $crop) {
                try {
                    $match = $service->findBestAutoMatchFromGdImage($crop, 'embedding_content');
                } finally {
                    imagedestroy($crop);
                }

                if ($match !== null) {
                    break;
                }
            }
        }

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertContains($match['strategy'], ['embedding', 'embedding_content']);
    }
}
