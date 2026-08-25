<?php

namespace Tests\Unit;

use App\Services\Admin\ProductImageHashService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScreenshotCropCalibrationTest extends TestCase
{
    /**
     * @return array<string, array{0: callable(): string, 1: array{top_min: float, top_max: float, height_min: float, height_max: float, width_min: float, strategies: list<string>}}>
     */
    public static function calibratedScreenshotFixtures(): array
    {
        return [
            'facebook_viewer_wood_platter' => [
                fn (): string => self::facebookViewerScreenshot(
                    photoTopFraction: 0.16,
                    photoHeightFraction: 0.34,
                    background: [150, 110, 60],
                ),
                [
                    'top_min' => 0.10,
                    'top_max' => 0.24,
                    'height_min' => 0.24,
                    'height_max' => 0.46,
                    'width_min' => 0.80,
                    'strategies' => ['photo_panel', 'trim'],
                ],
            ],
            'facebook_viewer_golden_model' => [
                fn (): string => self::facebookViewerScreenshot(
                    photoTopFraction: 0.18,
                    photoHeightFraction: 0.42,
                    background: [196, 154, 72],
                ),
                [
                    'top_min' => 0.12,
                    'top_max' => 0.26,
                    'height_min' => 0.30,
                    'height_max' => 0.52,
                    'width_min' => 0.80,
                    'strategies' => ['photo_panel', 'trim'],
                ],
            ],
            'facebook_ad_with_thumbnails' => [
                fn (): string => self::facebookAdScreenshot(),
                [
                    'top_min' => 0.20,
                    'top_max' => 0.34,
                    'height_min' => 0.18,
                    'height_max' => 0.30,
                    'width_min' => 0.75,
                    'strategies' => ['photo_panel', 'trim', 'embedded_card'],
                ],
            ],
            'messenger_carousel_card' => [
                fn (): string => self::messengerCarouselScreenshot(),
                [
                    'top_min' => 0.34,
                    'top_max' => 0.52,
                    'height_min' => 0.14,
                    'height_max' => 0.34,
                    'width_min' => 0.55,
                    'strategies' => ['embedded_card', 'photo_panel', 'trim'],
                ],
            ],
            'white_facebook_post' => [
                fn (): string => self::whiteFacebookPostScreenshot(),
                [
                    'top_min' => 0.18,
                    'top_max' => 0.34,
                    'height_min' => 0.18,
                    'height_max' => 0.36,
                    'width_min' => 0.70,
                    'strategies' => ['light_letterbox', 'photo_panel', 'texture_panel', 'trim'],
                ],
            ],
        ];
    }

    #[Test]
    #[DataProvider('calibratedScreenshotFixtures')]
    public function synthetic_customer_screenshots_crop_within_expected_ranges(callable $factory, array $expected): void
    {
        $hasher = app(ProductImageHashService::class);
        $suggestion = $hasher->suggestScreenshotCropFractions($factory());

        $this->assertNotNull($suggestion, 'Expected a crop suggestion for calibrated screenshot fixture.');

        $this->assertContains(
            $suggestion['strategy'],
            $expected['strategies'],
            'Unexpected strategy: '.$suggestion['strategy'],
        );

        $this->assertGreaterThanOrEqual($expected['top_min'], $suggestion['top']);
        $this->assertLessThanOrEqual($expected['top_max'], $suggestion['top']);
        $this->assertGreaterThanOrEqual($expected['height_min'], $suggestion['height']);
        $this->assertLessThanOrEqual($expected['height_max'], $suggestion['height']);
        $this->assertGreaterThanOrEqual($expected['width_min'], $suggestion['width']);

        $this->assertLessThan(
            0.72,
            $suggestion['top'] + $suggestion['height'],
            'Crop should stop before the lower Facebook UI overlay.',
        );
    }

    #[Test]
    public function optional_real_fixture_directory_can_be_used_for_regression(): void
    {
        $fixtureDir = base_path('tests/fixtures/inbox-screenshots');
        if (! is_dir($fixtureDir)) {
            $this->markTestSkipped('Drop real customer screenshots in tests/fixtures/inbox-screenshots to extend calibration.');
        }

        $hasher = app(ProductImageHashService::class);
        $files = glob($fixtureDir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];

        if ($files === []) {
            $this->markTestSkipped('No image fixtures found in tests/fixtures/inbox-screenshots.');
        }

        foreach ($files as $file) {
            $bytes = file_get_contents($file);
            $this->assertNotFalse($bytes);

            $suggestion = $hasher->suggestScreenshotCropFractions($bytes);
            $this->assertNotNull(
                $suggestion,
                'Expected crop suggestion for fixture: '.basename($file),
            );
            $this->assertLessThan(
                0.78,
                $suggestion['top'] + $suggestion['height'],
                'Fixture crop includes too much bottom UI: '.basename($file),
            );
        }
    }

    /**
     * @param  array{0:int,1:int,2:int}  $background
     */
    private static function facebookViewerScreenshot(
        float $photoTopFraction,
        float $photoHeightFraction,
        array $background,
    ): string {
        $width = 1080;
        $height = 2340;
        $screenshot = imagecreatetruecolor($width, $height);
        $black = imagecolorallocate($screenshot, 6, 6, 8);
        imagefill($screenshot, 0, 0, $black);

        $photoTop = (int) round($height * $photoTopFraction);
        $photoHeight = (int) round($height * $photoHeightFraction);
        $photoLeft = (int) round($width * 0.04);
        $photoRight = (int) round($width * 0.96);
        $bg = imagecolorallocate($screenshot, $background[0], $background[1], $background[2]);
        $accent = imagecolorallocate($screenshot, 220, 220, 220);

        for ($y = $photoTop; $y < $photoTop + $photoHeight; $y++) {
            for ($x = $photoLeft; $x < $photoRight; $x++) {
                imagesetpixel($screenshot, $x, $y, (($x + $y) % 28) < 14 ? $bg : $accent);
            }
        }

        self::paintFacebookOverlay($screenshot, $width, $height, (int) round($height * 0.66));

        return self::pngBytes($screenshot);
    }

    private static function facebookAdScreenshot(): string
    {
        $width = 1080;
        $height = 2340;
        $screenshot = imagecreatetruecolor($width, $height);
        $black = imagecolorallocate($screenshot, 4, 4, 6);
        imagefill($screenshot, 0, 0, $black);

        $photoTop = (int) round($height * 0.26);
        $photoHeight = (int) round($height * 0.24);
        $photoLeft = (int) round($width * 0.05);
        $photoRight = (int) round($width * 0.95);
        $wood = imagecolorallocate($screenshot, 96, 58, 34);
        $silver = imagecolorallocate($screenshot, 190, 190, 198);

        for ($y = $photoTop; $y < $photoTop + $photoHeight; $y++) {
            for ($x = $photoLeft; $x < $photoRight; $x++) {
                imagesetpixel($screenshot, $x, $y, (($x / 12) + ($y / 10)) % 2 === 0 ? $wood : $silver);
            }
        }

        $thumbTop = (int) round($height * 0.53);
        for ($i = 0; $i < 4; $i++) {
            $x0 = (int) round($width * (0.05 + ($i * 0.23)));
            imagefilledrectangle($screenshot, $x0, $thumbTop, $x0 + 180, $thumbTop + 180, $silver);
        }

        self::paintFacebookOverlay($screenshot, $width, $height, (int) round($height * 0.64));

        return self::pngBytes($screenshot);
    }

    private static function messengerCarouselScreenshot(): string
    {
        $width = 1080;
        $height = 2340;
        $screenshot = imagecreatetruecolor($width, $height);
        $black = imagecolorallocate($screenshot, 0, 0, 0);
        imagefill($screenshot, 0, 0, $black);

        $cardTop = (int) round($height * 0.40);
        $cardHeight = (int) round($height * 0.22);
        $cardLeft = (int) round($width * 0.08);
        $cardRight = (int) round($width * 0.92);
        $product = imagecolorallocate($screenshot, 170, 178, 188);
        $accent = imagecolorallocate($screenshot, 90, 100, 120);

        for ($y = $cardTop; $y < $cardTop + $cardHeight; $y++) {
            for ($x = $cardLeft; $x < $cardRight; $x++) {
                imagesetpixel($screenshot, $x, $y, (($x + $y) % 20) < 10 ? $product : $accent);
            }
        }

        $buttonTop = $cardTop + $cardHeight + 24;
        imagefilledrectangle($screenshot, $cardLeft, $buttonTop, $cardRight, $buttonTop + 96, imagecolorallocate($screenshot, 58, 58, 62));

        return self::pngBytes($screenshot);
    }

    private static function whiteFacebookPostScreenshot(): string
    {
        $width = 1080;
        $height = 2340;
        $screenshot = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($screenshot, 245, 245, 245);
        imagefill($screenshot, 0, 0, $white);

        $headerBottom = (int) round($height * 0.20);
        imagefilledrectangle($screenshot, 0, 0, $width, $headerBottom, $white);

        $photoTop = (int) round($height * 0.24);
        $photoHeight = (int) round($height * 0.24);
        $photoLeft = (int) round($width * 0.04);
        $photoRight = (int) round($width * 0.96);
        $saree = imagecolorallocate($screenshot, 88, 96, 118);
        $gold = imagecolorallocate($screenshot, 196, 168, 96);

        for ($y = $photoTop; $y < $photoTop + $photoHeight; $y++) {
            for ($x = $photoLeft; $x < $photoRight; $x++) {
                imagesetpixel($screenshot, (int) $x, (int) $y, (((int) ($x / 16)) % 2) === 0 ? $saree : $gold);
            }
        }

        $footerTop = (int) round($height * 0.52);
        imagefilledrectangle($screenshot, 0, $footerTop, $width, $height, $white);
        imagefilledrectangle($screenshot, 48, $footerTop + 40, $width - 48, $footerTop + 140, imagecolorallocate($screenshot, 24, 119, 242));

        return self::pngBytes($screenshot);
    }

    private static function paintFacebookOverlay(\GdImage $screenshot, int $width, int $height, int $overlayTop): void
    {
        $white = imagecolorallocate($screenshot, 235, 235, 235);
        $blue = imagecolorallocate($screenshot, 24, 119, 242);
        $dark = imagecolorallocate($screenshot, 20, 20, 24);

        for ($y = $overlayTop; $y < (int) round($height * 0.90); $y++) {
            imagefilledrectangle($screenshot, 0, $y, $width, $y, $dark);
        }

        imagestring($screenshot, 5, 48, $overlayTop + 24, 'Sundoritoma', $white);
        imagefilledrectangle($screenshot, 48, $overlayTop + 120, $width - 48, $overlayTop + 220, $blue);
        imagestring($screenshot, 5, 72, $overlayTop + 160, 'Send message', $white);
    }

    private static function pngBytes(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
