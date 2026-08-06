<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Support\CleanJpegWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ProductPricedImageService
{
    public const POSITIONS = [
        'top-left',
        'top-right',
        'bottom-left',
        'bottom-right',
        'center',
    ];

    public const FONT_MIN = 28;

    public const FONT_MAX = 96;

    public const FONT_DEFAULT = 56;

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

    /**
     * @return array{position: string, font: int}
     */
    public function defaultLayout(): array
    {
        return [
            'position' => 'top-left',
            'font' => self::FONT_DEFAULT,
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{position: string, font: int}
     */
    public function normalizeLayout(array $layout): array
    {
        $defaults = $this->defaultLayout();

        $position = $layout['position'] ?? null;

        if (! is_string($position) || ! in_array($position, self::POSITIONS, true)) {
            $position = $this->guessPositionFromLegacyCoordinates($layout) ?? $defaults['position'];
        }

        $font = (int) ($layout['font'] ?? $defaults['font']);

        // Legacy GD built-in font indexes 1–5 → readable TTF pixel sizes.
        if ($font >= 1 && $font <= 5) {
            $font = match ($font) {
                1 => 32,
                2 => 40,
                3 => 48,
                4 => 56,
                default => 64,
            };
        }

        return [
            'position' => $position,
            'font' => min(self::FONT_MAX, max(self::FONT_MIN, $font)),
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

    public function fontPath(): string
    {
        $bundled = resource_path('fonts/DejaVuSans-Bold.ttf');

        if (is_file($bundled)) {
            return $bundled;
        }

        $system = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

        if (is_file($system)) {
            return $system;
        }

        throw new RuntimeException('Priced image font file is missing.');
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    private function guessPositionFromLegacyCoordinates(array $layout): ?string
    {
        if (! array_key_exists('x', $layout) && ! array_key_exists('y', $layout)) {
            return null;
        }

        $x = (int) ($layout['x'] ?? 0);
        $y = (int) ($layout['y'] ?? 0);

        // Without image size, treat large offsets as "toward the opposite edge".
        $right = $x >= 120;
        $bottom = $y >= 120;

        return match (true) {
            $right && $bottom => 'bottom-right',
            $right && ! $bottom => 'top-right',
            ! $right && $bottom => 'bottom-left',
            default => 'top-left',
        };
    }

    /**
     * @param  array{position: string, font: int}  $layout
     */
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

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        $fontSize = $layout['font'];
        $fontFile = $this->fontPath();
        $padding = max(14, (int) round($fontSize * 0.35));
        $lineGap = max(8, (int) round($fontSize * 0.25));
        $margin = max(16, (int) round(min($width, $height) * 0.03));
        $panelWhite = imagecolorallocatealpha($canvas, 255, 255, 255, 45);
        $black = imagecolorallocate($canvas, 0, 0, 0);

        $lines = [];
        if ($product->compare_at_price !== null && (float) $product->compare_at_price > (float) $product->price) {
            $lines[] = ['text' => 'Tk '.number_format((float) $product->compare_at_price, 0), 'strike' => true];
        }
        $lines[] = ['text' => 'Tk '.number_format((float) $product->price, 0), 'strike' => false];

        $maxWidth = 0;
        $lineHeights = [];
        foreach ($lines as $line) {
            $box = imagettfbbox($fontSize, 0, $fontFile, $line['text']);
            if ($box === false) {
                imagedestroy($canvas);
                throw new RuntimeException('Could not measure priced image text.');
            }
            $textWidth = (int) abs($box[2] - $box[0]);
            $textHeight = (int) abs($box[7] - $box[1]);
            $maxWidth = max($maxWidth, $textWidth);
            $lineHeights[] = $textHeight;
        }

        $textBlockHeight = array_sum($lineHeights) + ((count($lines) - 1) * $lineGap);
        $panelWidth = $maxWidth + ($padding * 2);
        $panelHeight = $textBlockHeight + ($padding * 2);

        [$x, $y] = $this->panelOrigin($layout['position'], $width, $height, $panelWidth, $panelHeight, $margin);

        imagefilledrectangle($canvas, $x, $y, $x + $panelWidth, $y + $panelHeight, $panelWhite);

        $cursorY = $y + $padding;
        foreach ($lines as $index => $line) {
            $lineHeight = $lineHeights[$index];
            // imagettftext uses baseline Y.
            $baseline = $cursorY + $lineHeight;
            imagettftext($canvas, $fontSize, 0, $x + $padding, $baseline, $black, $fontFile, $line['text']);

            if ($line['strike']) {
                $box = imagettfbbox($fontSize, 0, $fontFile, $line['text']);
                $textWidth = (int) abs($box[2] - $box[0]);
                $strikeThickness = max(3, (int) round($fontSize * 0.1));
                $strikeCenterY = $cursorY + (int) floor($lineHeight / 2);
                $strikeTop = $strikeCenterY - (int) floor($strikeThickness / 2);
                imagefilledrectangle(
                    $canvas,
                    $x + $padding,
                    $strikeTop,
                    $x + $padding + $textWidth,
                    $strikeTop + $strikeThickness - 1,
                    $black
                );
            }

            $cursorY += $lineHeight + $lineGap;
        }

        CleanJpegWriter::write($canvas, $destination, 90);
        imagedestroy($canvas);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function panelOrigin(string $position, int $imageWidth, int $imageHeight, int $panelWidth, int $panelHeight, int $margin): array
    {
        $maxX = max($margin, $imageWidth - $panelWidth - $margin);
        $maxY = max($margin, $imageHeight - $panelHeight - $margin);

        return match ($position) {
            'top-right' => [$maxX, $margin],
            'bottom-left' => [$margin, $maxY],
            'bottom-right' => [$maxX, $maxY],
            'center' => [
                (int) max($margin, round(($imageWidth - $panelWidth) / 2)),
                (int) max($margin, round(($imageHeight - $panelHeight) / 2)),
            ],
            default => [$margin, $margin],
        };
    }

    private function directory(int $productId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'products-priced', (string) $productId]));
    }
}
