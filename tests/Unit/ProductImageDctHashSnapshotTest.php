<?php

namespace Tests\Unit;

use App\Services\Admin\ProductImageHashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductImageDctHashSnapshotTest extends TestCase
{
    #[Test]
    public function low_frequency_dct_matches_the_full_spectrum_hash(): void
    {
        $image = imagecreatetruecolor(48, 48);
        $red = imagecolorallocate($image, 200, 40, 60);
        $blue = imagecolorallocate($image, 40, 90, 200);

        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < 48; $x++) {
                imagesetpixel($image, $x, $y, ((int) ($x / 6) % 2) === 0 ? $red : $blue);
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->assertSame(
            'ef9a613621a59800',
            app(ProductImageHashService::class)->dctHashBinary($bytes),
        );
    }
}
