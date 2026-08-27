<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageEmbeddingService;
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
}
