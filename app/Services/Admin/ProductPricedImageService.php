<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Support\Bangla;
use App\Support\CleanJpegWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
        'custom',
    ];

    public const FONT_MIN = 28;

    public const FONT_MAX = 96;

    public const FONT_DEFAULT = 56;

    public const AUTO_FONT_MAX = 240;

    public const AUTO_SIZE_RATIO = 0.20;

    public const LOGO_SIZE_MIN = 8;

    public const LOGO_SIZE_MAX = 40;

    public const LOGO_SIZE_DEFAULT = 18;

    public const LOGO_PUBLIC_PATH = '/img/settings/logo.png';

    /**
     * Bundled HarfBuzz-shaped "/unit" PNGs (GD cannot shape Bengali).
     *
     * @var array<string, string>
     */
    private const UNIT_SUFFIX_ASSETS = [
        'পিস' => 'pis.png',
        'জোড়া' => 'jora.png',
        'সেট' => 'set.png',
    ];

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

        $layout = $this->resolveLayout($product, $source, $layout);
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
     * Centered overlay sized so the stamp panel is ~20% of the primary image width.
     *
     * @return array{position: string, font: int, x?: float, y?: float}
     */
    public function autoFillLayout(string $sourcePath, Product $product): array
    {
        $info = @getimagesize($sourcePath);
        $width = (int) ($info[0] ?? 800);
        $target = max(self::FONT_MIN, (int) round($width * self::AUTO_SIZE_RATIO));

        $bestFont = self::FONT_DEFAULT;
        $bestDiff = PHP_INT_MAX;

        for ($font = self::FONT_MIN; $font <= self::AUTO_FONT_MAX; $font++) {
            $panelWidth = $this->overlayMetrics($product, $font)['panelWidth'];
            $diff = abs($panelWidth - $target);

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestFont = $font;
            }

            // Once the panel is already wider than the target, larger fonts only get further away.
            if ($panelWidth >= $target) {
                break;
            }
        }

        return [
            'position' => 'center',
            'font' => $bestFont,
            'x' => 0.5,
            'y' => 0.5,
            'logo' => false,
            'logo_position' => 'top-right',
            'logo_size' => self::LOGO_SIZE_DEFAULT,
            'logo_x' => 0.88,
            'logo_y' => 0.12,
        ];
    }

    /**
     * @return array{width: int, height: int}
     */
    public function measurePanel(Product $product, int $fontSize): array
    {
        $metrics = $this->overlayMetrics($product, $fontSize);

        return [
            'width' => $metrics['panelWidth'],
            'height' => $metrics['panelHeight'],
        ];
    }

    /**
     * @return array{position: string, font: int, logo: bool, logo_position: string, logo_size: int}
     */
    public function defaultLayout(): array
    {
        return [
            'position' => 'top-left',
            'font' => self::FONT_DEFAULT,
            'logo' => false,
            'logo_position' => 'top-right',
            'logo_size' => self::LOGO_SIZE_DEFAULT,
        ];
    }

    /**
     * Approximate center (0–1) for a named corner / center snap.
     *
     * @return array{0: float, 1: float}
     */
    public function centerForPosition(string $position): array
    {
        return match ($position) {
            'top-right' => [0.88, 0.12],
            'bottom-left' => [0.12, 0.88],
            'bottom-right' => [0.88, 0.88],
            'center' => [0.5, 0.5],
            'custom' => [0.5, 0.5],
            default => [0.12, 0.12],
        };
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{
     *     position: string,
     *     font: int,
     *     x?: float,
     *     y?: float,
     *     logo: bool,
     *     logo_position: string,
     *     logo_size: int,
     *     logo_x?: float,
     *     logo_y?: float
     * }
     */
    public function normalizeLayout(array $layout): array
    {
        $defaults = $this->defaultLayout();

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

        $font = min(self::AUTO_FONT_MAX, max(self::FONT_MIN, $font));
        $normalizedCenter = $this->extractNormalizedCenter($layout);

        $position = $layout['position'] ?? null;

        if (! is_string($position) || ! in_array($position, self::POSITIONS, true)) {
            if ($normalizedCenter !== null) {
                $position = 'custom';
            } else {
                $position = $this->guessPositionFromLegacyCoordinates($layout) ?? $defaults['position'];
            }
        }

        $logoEnabled = (bool) ($layout['logo'] ?? $defaults['logo']);
        $logoPosition = $layout['logo_position'] ?? $defaults['logo_position'];

        if (! is_string($logoPosition) || ! in_array($logoPosition, self::POSITIONS, true)) {
            $logoPosition = $defaults['logo_position'];
        }

        $logoSize = (int) ($layout['logo_size'] ?? $defaults['logo_size']);
        $logoSize = min(self::LOGO_SIZE_MAX, max(self::LOGO_SIZE_MIN, $logoSize));

        $logoCenter = $this->extractLogoNormalizedCenter($layout);
        if ($logoCenter === null) {
            $logoCenter = $this->centerForPosition($logoPosition);
        }

        $result = [
            'position' => $position,
            'font' => $font,
            'logo' => $logoEnabled,
            'logo_position' => $logoPosition,
            'logo_size' => $logoSize,
            'logo_x' => $logoCenter[0],
            'logo_y' => $logoCenter[1],
        ];

        if ($normalizedCenter !== null) {
            $result['x'] = $normalizedCenter[0];
            $result['y'] = $normalizedCenter[1];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{0: float, 1: float}|null
     */
    private function extractLogoNormalizedCenter(array $layout): ?array
    {
        if (! array_key_exists('logo_x', $layout) || ! array_key_exists('logo_y', $layout)) {
            return null;
        }

        $x = (float) $layout['logo_x'];
        $y = (float) $layout['logo_y'];

        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return null;
        }

        return [round($x, 4), round($y, 4)];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{0: float, 1: float}|null
     */
    private function extractNormalizedCenter(array $layout): ?array
    {
        if (! array_key_exists('x', $layout) || ! array_key_exists('y', $layout)) {
            return null;
        }

        $x = (float) $layout['x'];
        $y = (float) $layout['y'];

        // Pixel legacy offsets (e.g. 24, 200) sit outside the unit square.
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return null;
        }

        $position = $layout['position'] ?? null;

        if (is_string($position) && in_array($position, self::POSITIONS, true)) {
            return [round($x, 4), round($y, 4)];
        }

        // No position key: fractional values are normalized centers; whole 0/1 stay legacy.
        if ($x != (int) $x || $y != (int) $y) {
            return [round($x, 4), round($y, 4)];
        }

        return null;
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
        $bundled = resource_path('fonts/NotoSansBengali-Bold.ttf');

        if (is_file($bundled)) {
            return $bundled;
        }

        $system = '/usr/share/fonts/truetype/noto/NotoSansBengali-Bold.ttf';

        if (is_file($system)) {
            return $system;
        }

        throw new RuntimeException('Priced image Bangla font file is missing.');
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{position: string, font: int, x?: float, y?: float}
     */
    private function resolveLayout(Product $product, string $source, ?array $layout): array
    {
        if ($layout !== null) {
            return $this->normalizeLayout($layout);
        }

        $saved = $product->priced_image_layout;

        if (is_array($saved) && $saved !== []) {
            return $this->normalizeLayout($saved);
        }

        return $this->autoFillLayout($source, $product);
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
     * @param  array{position: string, font: int, x?: float, y?: float}  $layout
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
        $metrics = $this->overlayMetrics($product, $fontSize);
        $padding = $metrics['padding'];
        $lineGap = $metrics['lineGap'];
        $lineHeights = $metrics['lineHeights'];
        $lines = $metrics['lines'];
        $panelWidth = $metrics['panelWidth'];
        $panelHeight = $metrics['panelHeight'];
        $margin = max(16, (int) round(min($width, $height) * 0.03));
        $panelWhite = imagecolorallocatealpha($canvas, 255, 255, 255, 45);
        $black = imagecolorallocate($canvas, 0, 0, 0);

        [$x, $y] = $this->panelOrigin($layout, $width, $height, $panelWidth, $panelHeight, $margin);

        $this->frostPanel($canvas, $x, $y, $panelWidth, $panelHeight);
        imagefilledrectangle($canvas, $x, $y, $x + $panelWidth, $y + $panelHeight, $panelWhite);

        $cursorY = $y + $padding;
        foreach ($lines as $index => $line) {
            $lineHeight = $lineHeights[$index];
            // imagettftext uses baseline Y.
            $baseline = $cursorY + $lineHeight;
            $textX = $x + $padding;
            imagettftext($canvas, $fontSize, 0, $textX, $baseline, $black, $fontFile, $line['text']);

            $box = imagettfbbox($fontSize, 0, $fontFile, $line['text']);
            $textWidth = (int) abs($box[2] - $box[0]);

            if ($line['strike']) {
                $strikeThickness = max(3, (int) round($fontSize * 0.1));
                $strikeCenterY = $cursorY + (int) floor($lineHeight / 2);
                $strikeTop = $strikeCenterY - (int) floor($strikeThickness / 2);
                imagefilledrectangle(
                    $canvas,
                    $textX,
                    $strikeTop,
                    $textX + $textWidth,
                    $strikeTop + $strikeThickness - 1,
                    $black
                );
            }

            if (($line['piece_suffix'] ?? false) === true) {
                $this->blitUnitSuffix(
                    $canvas,
                    $product,
                    $textX + $textWidth,
                    $cursorY,
                    $lineHeight,
                    $fontSize,
                );
            }

            $cursorY += $lineHeight + $lineGap;
        }

        $this->blitLogo($canvas, $layout, $width, $height);

        CleanJpegWriter::write($canvas, $destination, 90);
        imagedestroy($canvas);
    }

    /**
     * @param  \GdImage  $canvas
     * @param  array{
     *     position: string,
     *     font: int,
     *     x?: float,
     *     y?: float,
     *     logo?: bool,
     *     logo_position?: string,
     *     logo_size?: int,
     *     logo_x?: float,
     *     logo_y?: float
     * }  $layout
     */
    private function blitLogo($canvas, array $layout, int $width, int $height): void
    {
        if (! ($layout['logo'] ?? false)) {
            return;
        }

        $logoPath = public_path(ltrim(self::LOGO_PUBLIC_PATH, '/'));

        if (! is_file($logoPath) || ! is_readable($logoPath)) {
            return;
        }

        $logo = @imagecreatefrompng($logoPath);

        if ($logo === false) {
            return;
        }

        imagesavealpha($logo, true);
        imagealphablending($logo, true);

        $naturalW = imagesx($logo);
        $naturalH = imagesy($logo);

        if ($naturalW < 1 || $naturalH < 1) {
            imagedestroy($logo);

            return;
        }

        $sizePercent = (int) ($layout['logo_size'] ?? self::LOGO_SIZE_DEFAULT);
        $sizePercent = min(self::LOGO_SIZE_MAX, max(self::LOGO_SIZE_MIN, $sizePercent));
        $targetWidth = max(24, (int) round($width * ($sizePercent / 100)));
        $scale = $targetWidth / $naturalW;
        $targetHeight = max(1, (int) round($naturalH * $scale));

        $edgePad = max(8, (int) round(min($width, $height) * 0.02));
        $centerX = (float) ($layout['logo_x'] ?? $this->centerForPosition((string) ($layout['logo_position'] ?? 'top-right'))[0]);
        $centerY = (float) ($layout['logo_y'] ?? $this->centerForPosition((string) ($layout['logo_position'] ?? 'top-right'))[1]);
        $centerX = max(0, min(1, $centerX));
        $centerY = max(0, min(1, $centerY));

        $x = (int) round(($centerX * $width) - ($targetWidth / 2));
        $y = (int) round(($centerY * $height) - ($targetHeight / 2));
        $x = max($edgePad, min($width - $targetWidth - $edgePad, $x));
        $y = max($edgePad, min($height - $targetHeight - $edgePad, $y));

        imagecopyresampled(
            $canvas,
            $logo,
            $x,
            $y,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $naturalW,
            $naturalH,
        );

        imagedestroy($logo);
    }

    /**
     * @return array{
     *     lines: list<array{text: string, strike: bool, piece_suffix?: bool}>,
     *     lineHeights: list<int>,
     *     padding: int,
     *     lineGap: int,
     *     panelWidth: int,
     *     panelHeight: int
     * }
     */
    private function overlayMetrics(Product $product, int $fontSize): array
    {
        $fontFile = $this->fontPath();
        $padding = max(14, (int) round($fontSize * 0.35));
        $lineGap = max(8, (int) round($fontSize * 0.25));

        $lines = [];
        if ($product->compare_at_price !== null && (float) $product->compare_at_price > (float) $product->price) {
            $lines[] = ['text' => $this->toBanglaDigits((float) $product->compare_at_price), 'strike' => true];
        }
        // Sale line is Taka + digits only; "/{unit}" is composited from a shaped PNG
        // because PHP GD cannot OpenType-shape Bengali (ি-kar / ে-kar / etc.).
        $lines[] = [
            'text' => '৳'.$this->toBanglaDigits((float) $product->price),
            'strike' => false,
            'piece_suffix' => true,
        ];

        $maxWidth = 0;
        $lineHeights = [];
        foreach ($lines as $line) {
            $box = imagettfbbox($fontSize, 0, $fontFile, $line['text']);
            if ($box === false) {
                throw new RuntimeException('Could not measure priced image text.');
            }
            $textWidth = (int) abs($box[2] - $box[0]);
            $textHeight = (int) abs($box[7] - $box[1]);

            if (($line['piece_suffix'] ?? false) === true) {
                $suffix = $this->unitSuffixSizeForFont($product, $fontSize);
                $textWidth += $suffix['width'];
                $textHeight = max($textHeight, $suffix['height']);
            }

            $maxWidth = max($maxWidth, $textWidth);
            $lineHeights[] = $textHeight;
        }

        $textBlockHeight = array_sum($lineHeights) + ((count($lines) - 1) * $lineGap);

        return [
            'lines' => $lines,
            'lineHeights' => $lineHeights,
            'padding' => $padding,
            'lineGap' => $lineGap,
            'panelWidth' => $maxWidth + ($padding * 2),
            'panelHeight' => $textBlockHeight + ($padding * 2),
        ];
    }

    /**
     * Format a whole-taka amount with Bangla digits (no thousands separator).
     */
    public function toBanglaDigits(float|int $amount): string
    {
        return Bangla::digits((string) (int) round($amount));
    }

    /**
     * Absolute path to a black-on-white "/{unit}" PNG for the product's price unit.
     */
    public function unitSuffixPath(Product $product): string
    {
        $unit = $product->priceUnitLabel();

        if (isset(self::UNIT_SUFFIX_ASSETS[$unit])) {
            $path = resource_path('images/priced-units/'.self::UNIT_SUFFIX_ASSETS[$unit]);

            if (! is_file($path)) {
                throw new RuntimeException('Priced image unit asset is missing for “'.$unit.'”.');
            }

            return $path;
        }

        return $this->ensureCustomUnitSuffixPng($unit);
    }

    /**
     * @deprecated Use unitSuffixPath(); kept for older tests expecting the পিস asset.
     */
    public function pieceSuffixPath(): string
    {
        $path = resource_path('images/priced-units/pis.png');

        if (! is_file($path)) {
            $legacy = resource_path('images/priced-stamp-piece-suffix.png');

            if (is_file($legacy)) {
                return $legacy;
            }

            throw new RuntimeException('Priced image piece-suffix asset is missing.');
        }

        return $path;
    }

    /**
     * @return array{width: int, height: int}
     */
    private function unitSuffixSizeForFont(Product $product, int $fontSize): array
    {
        $info = @getimagesize($this->unitSuffixPath($product));
        $nativeW = max(1, (int) ($info[0] ?? 1));
        $nativeH = max(1, (int) ($info[1] ?? 1));
        $height = max(1, (int) round($fontSize * 1.45));
        $width = max(1, (int) round($nativeW * ($height / $nativeH)));

        return ['width' => $width, 'height' => $height];
    }

    private function blitUnitSuffix(
        \GdImage $canvas,
        Product $product,
        int $x,
        int $lineTop,
        int $lineHeight,
        int $fontSize,
    ): void {
        $suffix = @imagecreatefrompng($this->unitSuffixPath($product));

        if ($suffix === false) {
            throw new RuntimeException('Could not load priced image unit suffix.');
        }

        $size = $this->unitSuffixSizeForFont($product, $fontSize);
        $scaled = imagecreatetruecolor($size['width'], $size['height']);

        if ($scaled === false) {
            imagedestroy($suffix);
            throw new RuntimeException('Could not scale priced image unit suffix.');
        }

        $white = imagecolorallocate($scaled, 255, 255, 255);
        imagefill($scaled, 0, 0, $white);
        imagecopyresampled(
            $scaled,
            $suffix,
            0,
            0,
            0,
            0,
            $size['width'],
            $size['height'],
            imagesx($suffix),
            imagesy($suffix),
        );
        imagedestroy($suffix);

        $destY = $lineTop + (int) max(0, round(($lineHeight - $size['height']) / 2));
        $black = imagecolorallocate($canvas, 0, 0, 0);

        for ($sy = 0; $sy < $size['height']; $sy++) {
            for ($sx = 0; $sx < $size['width']; $sx++) {
                $rgb = imagecolorat($scaled, $sx, $sy);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r > 230 && $g > 230 && $b > 230) {
                    continue;
                }

                imagesetpixel($canvas, $x + $sx, $destY + $sy, $black);
            }
        }

        imagedestroy($scaled);
    }

    /**
     * Render and cache a custom unit suffix with HarfBuzz when available, else GD.
     */
    private function ensureCustomUnitSuffixPng(string $unit): string
    {
        $hash = hash('sha256', $unit);
        $cached = storage_path('app/priced-unit-suffixes/'.$hash.'.png');

        if (is_file($cached)) {
            return $cached;
        }

        File::ensureDirectoryExists(dirname($cached));

        $hbView = $this->hbViewBinary();

        if ($hbView !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'priced-unit-');

            if ($tmp === false) {
                throw new RuntimeException('Could not create temp file for priced unit suffix.');
            }

            $tmpPng = $tmp.'.png';
            @unlink($tmp);

            $result = Process::run([
                $hbView,
                '-O', 'png',
                '-o', $tmpPng,
                '--font-size=160',
                '--margin=4',
                '--background=FFFFFF',
                '--foreground=000000',
                $this->fontPath(),
                '/'.$unit,
            ]);

            if ($result->successful() && is_file($tmpPng)) {
                $this->trimWhitePngToFile($tmpPng, $cached);
                @unlink($tmpPng);

                return $cached;
            }

            @unlink($tmpPng);
        }

        $this->renderUnitSuffixWithGd($unit, $cached);

        return $cached;
    }

    private function hbViewBinary(): ?string
    {
        foreach (['/usr/bin/hb-view', '/usr/local/bin/hb-view'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function trimWhitePngToFile(string $sourcePath, string $destinationPath): void
    {
        $src = @imagecreatefrompng($sourcePath);

        if ($src === false) {
            throw new RuntimeException('Could not read generated unit suffix PNG.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r < 245 || $g < 245 || $b < 245) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            imagedestroy($src);
            throw new RuntimeException('Generated unit suffix PNG was empty.');
        }

        $pad = 2;
        $tw = $maxX - $minX + 1;
        $th = $maxY - $minY + 1;
        $dst = imagecreatetruecolor($tw + ($pad * 2), $th + ($pad * 2));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, $pad, $pad, $minX, $minY, $tw, $th);
        imagedestroy($src);
        imagepng($dst, $destinationPath, 9);
        imagedestroy($dst);
    }

    private function renderUnitSuffixWithGd(string $unit, string $destinationPath): void
    {
        $fontFile = $this->fontPath();
        $fontSize = 96;
        $text = '/'.$unit;
        $box = imagettfbbox($fontSize, 0, $fontFile, $text);

        if ($box === false) {
            throw new RuntimeException('Could not measure custom price unit for stamp.');
        }

        $textWidth = (int) abs($box[2] - $box[0]);
        $textHeight = (int) abs($box[7] - $box[1]);
        $pad = 8;
        $img = imagecreatetruecolor($textWidth + ($pad * 2), $textHeight + ($pad * 2));
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagettftext(
            $img,
            $fontSize,
            0,
            $pad,
            $pad + $textHeight,
            imagecolorallocate($img, 0, 0, 0),
            $fontFile,
            $text,
        );
        imagepng($img, $destinationPath, 9);
        imagedestroy($img);
    }

    private function frostPanel(\GdImage $canvas, int $x, int $y, int $panelWidth, int $panelHeight): void
    {
        $canvasW = imagesx($canvas);
        $canvasH = imagesy($canvas);
        $x = max(0, $x);
        $y = max(0, $y);
        $panelWidth = min($panelWidth, $canvasW - $x);
        $panelHeight = min($panelHeight, $canvasH - $y);

        if ($panelWidth < 2 || $panelHeight < 2) {
            return;
        }

        $region = imagecrop($canvas, [
            'x' => $x,
            'y' => $y,
            'width' => $panelWidth,
            'height' => $panelHeight,
        ]);

        if ($region === false) {
            return;
        }

        for ($i = 0; $i < 12; $i++) {
            imagefilter($region, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagecopy($canvas, $region, $x, $y, 0, 0, $panelWidth, $panelHeight);
        imagedestroy($region);
    }

    /**
     * @param  array{position: string, font: int, x?: float, y?: float}  $layout
     * @return array{0: int, 1: int}
     */
    private function panelOrigin(array $layout, int $imageWidth, int $imageHeight, int $panelWidth, int $panelHeight, int $margin): array
    {
        $maxX = max(0, $imageWidth - $panelWidth);
        $maxY = max(0, $imageHeight - $panelHeight);

        if (array_key_exists('x', $layout) && array_key_exists('y', $layout)) {
            $centerX = ((float) $layout['x']) * $imageWidth;
            $centerY = ((float) $layout['y']) * $imageHeight;
            $x = (int) round($centerX - ($panelWidth / 2));
            $y = (int) round($centerY - ($panelHeight / 2));

            return [
                max(0, min($maxX, $x)),
                max(0, min($maxY, $y)),
            ];
        }

        $snapMaxX = max($margin, $imageWidth - $panelWidth - $margin);
        $snapMaxY = max($margin, $imageHeight - $panelHeight - $margin);

        return match ($layout['position']) {
            'top-right' => [$snapMaxX, $margin],
            'bottom-left' => [$margin, $snapMaxY],
            'bottom-right' => [$snapMaxX, $snapMaxY],
            'center', 'custom' => [
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
