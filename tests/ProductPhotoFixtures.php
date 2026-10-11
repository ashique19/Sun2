<?php

namespace Tests;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductImageSignatureService;
use GdImage;

/**
 * Deterministic, textured "product photos" and phone-screenshot wrappers for visual matching tests.
 */
trait ProductPhotoFixtures
{
    /** @var list<string> */
    private array $productPhotoFiles = [];

    protected function tearDownProductPhotoFixtures(): void
    {
        foreach ($this->productPhotoFiles as $path) {
            @unlink($path);
        }
        $this->productPhotoFiles = [];
    }

    /**
     * A photo of random overlapping shapes; different seeds give visually unrelated photos.
     */
    protected function productPhotoBytes(int $seed, int $width = 320, int $height = 320, string $format = 'jpeg'): string
    {
        mt_srand($seed);

        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, mt_rand(150, 240), mt_rand(150, 240), mt_rand(150, 240)));

        for ($i = 0; $i < 16; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            $cx = mt_rand(0, $width);
            $cy = mt_rand(0, $height);
            $w = mt_rand((int) ($width * 0.12), (int) ($width * 0.55));
            $h = mt_rand((int) ($height * 0.12), (int) ($height * 0.55));
            if ($i % 2 === 0) {
                imagefilledellipse($image, $cx, $cy, $w, $h, $color);
            } else {
                imagefilledrectangle($image, $cx - intdiv($w, 2), $cy - intdiv($h, 2), $cx + intdiv($w, 2), $cy + intdiv($h, 2), $color);
            }
        }

        mt_srand();

        return $this->encodeFixtureImage($image, $format);
    }

    /**
     * The photo inside a tall phone screenshot (status bar, page header, caption, bottom nav).
     */
    protected function phoneScreenshotBytes(string $photoBytes, int $width = 360, int $height = 780): string
    {
        $screen = imagecreatetruecolor($width, $height);
        imagefilledrectangle($screen, 0, 0, $width, $height, imagecolorallocate($screen, 24, 25, 26));

        $text = imagecolorallocate($screen, 228, 230, 235);
        $muted = imagecolorallocate($screen, 140, 144, 150);
        imagefilledrectangle($screen, 12, 10, 60, 18, $text);
        imagefilledrectangle($screen, $width - 70, 10, $width - 12, 18, $text);
        imagefilledellipse($screen, 34, 70, 40, 40, imagecolorallocate($screen, 90, 120, 200));
        imagefilledrectangle($screen, 64, 56, 220, 66, $text);
        imagefilledrectangle($screen, 64, 76, 160, 84, $muted);
        imagefilledrectangle($screen, 12, 110, $width - 40, 120, $text);
        imagefilledrectangle($screen, 12, 128, $width - 120, 138, $text);

        $photo = imagecreatefromstring($photoBytes);
        $photoHeight = (int) round($width * imagesy($photo) / imagesx($photo));
        imagecopyresampled($screen, $photo, 0, 160, 0, 0, $width, $photoHeight, imagesx($photo), imagesy($photo));
        imagedestroy($photo);

        $captionTop = 160 + $photoHeight + 16;
        imagefilledrectangle($screen, 12, $captionTop, $width - 60, $captionTop + 10, $muted);
        imagefilledrectangle($screen, 12, $captionTop + 22, 140, $captionTop + 32, $muted);
        imagefilledrectangle($screen, 0, $height - 56, $width, $height, imagecolorallocate($screen, 36, 37, 38));
        for ($x = 30; $x < $width; $x += 75) {
            imagefilledellipse($screen, $x, $height - 28, 22, 22, $muted);
        }

        return $this->encodeFixtureImage($screen, 'png');
    }

    /**
     * A centered sub-region of a photo (a customer crop), upscaled back to the source size.
     */
    protected function croppedPhotoBytes(string $photoBytes, float $fraction = 0.7): string
    {
        $photo = imagecreatefromstring($photoBytes);
        $width = imagesx($photo);
        $height = imagesy($photo);
        $cropWidth = (int) round($width * $fraction);
        $cropHeight = (int) round($height * $fraction);

        $crop = imagecreatetruecolor($width, $height);
        imagecopyresampled($crop, $photo, 0, 0, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight);
        imagedestroy($photo);

        return $this->encodeFixtureImage($crop, 'jpeg');
    }

    protected function matchSignatureFor(string $photoBytes): string
    {
        $signatures = app(ProductImageSignatureService::class);

        return $signatures->pack($signatures->catalogSignatures($photoBytes));
    }

    /**
     * Published product with one primary catalog photo on disk, hashed and visually signed.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function catalogProduct(string $name, string $photoBytes, array $attributes = [], bool $signed = true): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'sku' => 'FX'.random_int(1000, 9999),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            ...$attributes,
        ]);

        $relativePath = 'img/products/'.$product->id.'/fixture-'.uniqid().'.jpg';
        $absolutePath = public_path($relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0775, true);
        }
        file_put_contents($absolutePath, $photoBytes);
        $this->productPhotoFiles[] = $absolutePath;

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativePath,
            'alt' => $name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => app(ProductImageHashService::class)->hashBinary($photoBytes),
            'match_signature' => $signed ? $this->matchSignatureFor($photoBytes) : null,
        ]);

        return $product->fresh(['images']);
    }

    private function encodeFixtureImage(GdImage $image, string $format): string
    {
        ob_start();
        if ($format === 'png') {
            imagepng($image);
        } else {
            imagejpeg($image, null, 90);
        }
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
