<?php

namespace App\Services\Admin;

use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ProductPricedImageService
{
    public function generate(Product $product, ?array $layout = null): string
    {
        $sourcePath = $product->primaryImagePath();

        if (! $sourcePath) {
            throw new RuntimeException('A primary product image is required first.');
        }

        $source = public_path(ltrim($sourcePath, '/'));

        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Primary product image is not readable.');
        }

        File::ensureDirectoryExists($this->directory($product->id));

        $layout = $this->normalizeLayout($layout ?? $product->priced_image_layout ?? []);
        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.jpg';
        $destination = $this->directory($product->id).DIRECTORY_SEPARATOR.$filename;

        $this->compose($source, $destination, $product, $layout);

        $oldPath = $product->priced_image_path;
        $path = '/img/products-priced/'.$product->id.'/'.$filename;

        $product->update([
            'priced_image_path' => $path,
            'priced_image_layout' => $layout,
        ]);

        if ($oldPath && $oldPath !== $path) {
            $this->deleteLocalFile($oldPath);
        }

        return $path;
    }

    public function clear(Product $product): void
    {
        $oldPath = $product->priced_image_path;
        $product->update(['priced_image_path' => null]);

        if ($oldPath) {
            $this->deleteLocalFile($oldPath);
        }
    }

    public function defaultLayout(): array
    {
        return [
            'x' => 24,
            'y' => 24,
            'font' => 5,
        ];
    }

    public function normalizeLayout(array $layout): array
    {
        $defaults = $this->defaultLayout();

        return [
            'x' => max(0, (int) ($layout['x'] ?? $defaults['x'])),
            'y' => max(0, (int) ($layout['y'] ?? $defaults['y'])),
            'font' => min(5, max(1, (int) ($layout['font'] ?? $defaults['font']))),
        ];
    }

    public function deleteLocalFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalized, 'img/products-priced/')) {
            return;
        }

        $absolute = public_path($normalized);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function compose(string $source, string $destination, Product $product, array $layout): void
    {
        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('Could not read the source image.');
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Unsupported image type for priced image generation.');
        }

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($image);
            throw new RuntimeException('Could not create image canvas.');
        }

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        $font = $layout['font'];
        $x = $layout['x'];
        $y = $layout['y'];
        $padding = 12;
        $lineGap = 10;
        $panelWhite = imagecolorallocatealpha($canvas, 255, 255, 255, 45);
        $black = imagecolorallocate($canvas, 0, 0, 0);

        $lines = [];
        if ($product->compare_at_price !== null && (float) $product->compare_at_price > (float) $product->price) {
            $lines[] = ['text' => 'Tk '.number_format((float) $product->compare_at_price, 0), 'strike' => true];
        }
        $lines[] = ['text' => 'Tk '.number_format((float) $product->price, 0), 'strike' => false];

        $maxWidth = 0;
        foreach ($lines as $line) {
            $maxWidth = max($maxWidth, imagefontwidth($font) * strlen($line['text']));
        }

        $lineHeight = imagefontheight($font);
        $panelWidth = $maxWidth + ($padding * 2);
        $panelHeight = (count($lines) * $lineHeight) + ((count($lines) - 1) * $lineGap) + ($padding * 2);

        imagefilledrectangle($canvas, $x, $y, $x + $panelWidth, $y + $panelHeight, $panelWhite);

        $cursorY = $y + $padding;
        foreach ($lines as $line) {
            imagestring($canvas, $font, $x + $padding, $cursorY, $line['text'], $black);

            if ($line['strike']) {
                $textWidth = imagefontwidth($font) * strlen($line['text']);
                $strikeY = $cursorY + (int) floor($lineHeight / 2);
                imageline($canvas, $x + $padding, $strikeY, $x + $padding + $textWidth, $strikeY, $black);
            }

            $cursorY += $lineHeight + $lineGap;
        }

        imagejpeg($canvas, $destination, 90);
        imagedestroy($canvas);
    }

    private function directory(int $productId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'products-priced', (string) $productId]));
    }
}
