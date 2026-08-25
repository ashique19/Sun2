<?php

namespace Tests\Unit;

use App\Services\Admin\ProductImageHashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TexturePhotoPanelTest extends TestCase
{
    #[Test]
    public function texture_panel_isolates_busy_product_region_in_screenshot(): void
    {
        $width = 480;
        $height = 960;
        $screenshot = imagecreatetruecolor($width, $height);
        $black = imagecolorallocate($screenshot, 8, 8, 10);
        imagefill($screenshot, 0, 0, $black);

        $photoTop = (int) round($height * 0.18);
        $photoHeight = (int) round($height * 0.40);
        $gold = imagecolorallocate($screenshot, 196, 154, 72);
        $maroon = imagecolorallocate($screenshot, 120, 24, 48);

        for ($y = $photoTop; $y < $photoTop + $photoHeight; $y++) {
            for ($x = 20; $x < $width - 20; $x++) {
                imagesetpixel($screenshot, $x, $y, (($x + $y) % 18) < 9 ? $gold : $maroon);
            }
        }

        // Flat-ish UI overlay (low texture relative to product).
        $dark = imagecolorallocate($screenshot, 22, 22, 26);
        imagefilledrectangle($screenshot, 0, (int) round($height * 0.66), $width, (int) round($height * 0.9), $dark);
        $blue = imagecolorallocate($screenshot, 24, 119, 242);
        imagefilledrectangle($screenshot, 40, (int) round($height * 0.72), $width - 40, (int) round($height * 0.8), $blue);

        ob_start();
        imagepng($screenshot);
        $bytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        $suggestion = app(ProductImageHashService::class)->suggestScreenshotCropFractions($bytes);

        $this->assertNotNull($suggestion);
        $this->assertContains($suggestion['strategy'], ['texture_panel', 'photo_panel', 'trim']);
        $this->assertGreaterThanOrEqual(0.12, $suggestion['top']);
        $this->assertLessThan(0.70, $suggestion['top'] + $suggestion['height']);
        $this->assertGreaterThan(0.30, $suggestion['height']);
    }
}
