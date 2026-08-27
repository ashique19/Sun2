<?php

namespace Tests\Unit;

use App\Services\Admin\ProductImageHashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageDctHashTest extends TestCase
{
    #[Test]
    public function dct_hash_is_stable_for_identical_pixels(): void
    {
        $hasher = app(ProductImageHashService::class);
        $bytes = $this->jpegBytes([40, 120, 200], pattern: true);

        $a = $hasher->dctHashBinary($bytes);
        $b = $hasher->dctHashBinary($bytes);

        $this->assertSame(16, strlen($a));
        $this->assertSame($a, $b);
    }

    #[Test]
    public function dct_hash_survives_jpeg_recompression_better_than_chance(): void
    {
        $hasher = app(ProductImageHashService::class);
        $original = $this->jpegBytes([180, 60, 40], pattern: true, quality: 95);
        $recompressed = $this->recompress($original, quality: 60);

        $hashA = $hasher->dctHashBinary($original);
        $hashB = $hasher->dctHashBinary($recompressed);
        $percent = $hasher->matchPercent($hashA, $hashB);

        $this->assertGreaterThanOrEqual(80.0, $percent, 'DCT pHash should tolerate moderate JPEG recompression');
    }

    #[Test]
    public function unrelated_images_have_low_dct_similarity(): void
    {
        $hasher = app(ProductImageHashService::class);
        $a = $hasher->dctHashBinary($this->jpegBytes([200, 40, 40], pattern: true));
        $b = $hasher->dctHashBinary($this->jpegBytes([40, 40, 200], pattern: false));

        $this->assertLessThan(80.0, $hasher->matchPercent($a, $b));
    }

    /**
     * @param  array{0:int,1:int,2:int}  $color
     */
    private function jpegBytes(array $color, bool $pattern = false, int $quality = 90): string
    {
        $image = imagecreatetruecolor(128, 128);
        $paint = imagecolorallocate($image, $color[0], $color[1], $color[2]);
        imagefilledrectangle($image, 0, 0, 127, 127, $paint);

        if ($pattern) {
            $accent = imagecolorallocate($image, 255 - $color[0], 255 - $color[1], 255 - $color[2]);
            imagefilledellipse($image, 64, 64, 50, 50, $accent);
            imagefilledrectangle($image, 10, 10, 40, 40, $accent);
        }

        ob_start();
        imagejpeg($image, null, $quality);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function recompress(string $jpeg, int $quality): string
    {
        $image = imagecreatefromstring($jpeg);
        $this->assertNotFalse($image);
        ob_start();
        imagejpeg($image, null, $quality);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
