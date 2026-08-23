<?php

namespace App\Services\Admin;

use App\Models\ProductImage;
use App\Support\StorefrontAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductImageHashService
{
    public const HASH_BITS = 64;

    public const MIN_MATCH_PERCENT = 80;

    public const AUTO_MATCH_PERCENT = 90;

    public const TOP_MATCHES = 3;

    /**
     * Downscale large images before dHash so decode→resample stays cheap.
     */
    public const HASH_MAX_EDGE = 512;

    /**
     * Center-crop fractions tried when full-frame match is below auto threshold.
     * Compared against stored full-frame catalog hashes only (no per-image rehash).
     *
     * @var list<float>
     */
    public const CENTER_CROP_SCALES = [0.7, 0.5, 0.4];

    /**
     * Rows/columns with gray std-dev below this are treated as screenshot chrome
     * (status bars, messenger margins, keyboard areas).
     */
    public const CHROME_LINE_STD_THRESHOLD = 10.0;

    /**
     * After trimming, keep at least this fraction of the original width/height.
     */
    public const TRIM_MIN_CONTENT_FRACTION = 0.15;

    public function hashFile(string $absolutePath, ?float $centerFraction = null): string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Image file is not readable: '.$absolutePath);
        }

        $binary = file_get_contents($absolutePath);

        if ($binary === false) {
            throw new RuntimeException('Could not read image file.');
        }

        return $this->hashBinary($binary, $centerFraction);
    }

    public function hashUploadedFile(UploadedFile $file, ?float $centerFraction = null): string
    {
        $path = $file->getRealPath();

        if (! $path) {
            throw new RuntimeException('Uploaded image path is missing.');
        }

        return $this->hashFile($path, $centerFraction);
    }

    public function hashBinary(string $binary, ?float $centerFraction = null): string
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

        $image = $this->downscaleForHash($image);

        if ($centerFraction !== null) {
            $image = $this->centerCrop($image, $centerFraction);
        }

        return $this->hashGdImage($image);
    }

    public function hashProductImage(ProductImage $image, bool $allowRemoteDownload = true): ?string
    {
        $local = $this->localPath($image->path);

        if ($local) {
            return $this->hashFile($local);
        }

        if (! $allowRemoteDownload) {
            return null;
        }

        $url = StorefrontAssets::url($image->path);

        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        $response = Http::timeout(20)->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $this->hashBinary($response->body());
    }

    public function storeHash(ProductImage $image, bool $allowRemoteDownload = true): ?string
    {
        $hash = $this->hashProductImage($image, $allowRemoteDownload);

        if ($hash === null) {
            return null;
        }

        $image->update(['perceptual_hash' => $hash]);

        return $hash;
    }

    public function hammingDistance(string $hashA, string $hashB): int
    {
        $a = hex2bin(str_pad($hashA, 16, '0', STR_PAD_LEFT));
        $b = hex2bin(str_pad($hashB, 16, '0', STR_PAD_LEFT));

        if ($a === false || $b === false) {
            return self::HASH_BITS;
        }

        $distance = 0;
        $length = min(strlen($a), strlen($b));

        for ($i = 0; $i < $length; $i++) {
            $xor = ord($a[$i]) ^ ord($b[$i]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    public function matchPercent(string $hashA, string $hashB): float
    {
        $distance = $this->hammingDistance($hashA, $hashB);

        return round(max(0, (1 - ($distance / self::HASH_BITS)) * 100), 1);
    }

    /**
     * Plan A: full-frame vs stored hashes.
     * Plan B: trim uniform screenshot chrome (status bars, margins).
     * Plan C: center-crop the query and compare to stored full-frame catalog hashes.
     *
     * @return array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int,strategy:string}|null
     */
    public function findBestAutoMatchFromBinary(string $binary): ?array
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

        $image = $this->downscaleForHash($image);

        try {
            foreach ($this->queryHashesFromImage($image) as $candidate) {
                $matches = $this->findTopMatches($candidate['hash'], 1, self::AUTO_MATCH_PERCENT);
                $top = $matches[0] ?? null;

                if ($top !== null && (float) $top['match_percent'] >= self::AUTO_MATCH_PERCENT) {
                    return $top + ['strategy' => $candidate['strategy']];
                }
            }
        } finally {
            imagedestroy($image);
        }

        return null;
    }

    /**
     * Try full-frame, chrome-trimmed, and center-cropped query hashes; return the best
     * matches per product across all strategies.
     *
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    public function findTopMatchesFromBinary(string $binary, int $limit = self::TOP_MATCHES, float $minPercent = self::MIN_MATCH_PERCENT): array
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

        $image = $this->downscaleForHash($image);

        try {
            $bestByProduct = [];

            foreach ($this->queryHashesFromImage($image) as $candidate) {
                foreach ($this->findTopMatches($candidate['hash'], $limit, $minPercent) as $match) {
                    $productId = (int) $match['product_id'];
                    $existing = $bestByProduct[$productId] ?? null;

                    if ($existing === null || (float) $match['match_percent'] > (float) $existing['match_percent']) {
                        $bestByProduct[$productId] = $match;
                    }
                }
            }

            $matches = array_values($bestByProduct);
            usort($matches, fn (array $a, array $b) => $b['match_percent'] <=> $a['match_percent']);

            return array_slice($matches, 0, $limit);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @return list<array{hash:string,strategy:string}>
     */
    private function queryHashesFromImage(\GdImage $image): array
    {
        $candidates = [
            [
                'hash' => $this->hashGdImageCopy($image),
                'strategy' => 'full',
            ],
        ];

        try {
            $trimmed = $this->trimScreenshotChromeCopy($image);

            if ($trimmed !== null) {
                $candidates[] = [
                    'hash' => $this->hashGdImage($trimmed),
                    'strategy' => 'query_trim_chrome_vs_catalog_full',
                ];
            }
        } catch (Throwable $e) {
            Log::debug('Screenshot chrome trim failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        foreach (self::CENTER_CROP_SCALES as $scale) {
            try {
                $cropped = $this->centerCropCopy($image, $scale);
                $candidates[] = [
                    'hash' => $this->hashGdImage($cropped),
                    'strategy' => 'query_center_'.$this->scaleLabel($scale).'_vs_catalog_full',
                ];
            } catch (Throwable $e) {
                Log::debug('Center-crop product image match failed.', [
                    'scale' => $scale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $candidates;
    }

    private function scaleLabel(float $scale): string
    {
        return rtrim(rtrim(number_format($scale, 2, '.', ''), '0'), '.');
    }

    /**
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    public function findTopMatches(string $hash, int $limit = self::TOP_MATCHES, float $minPercent = self::MIN_MATCH_PERCENT): array
    {
        $rows = ProductImage::query()
            ->whereNotNull('perceptual_hash')
            ->with(['product:id,name,sku,price,stock_quantity,slug'])
            ->get(['id', 'product_id', 'path', 'perceptual_hash']);

        $bestByProduct = [];

        foreach ($rows as $row) {
            if (! $row->product) {
                continue;
            }

            $distance = $this->hammingDistance($hash, (string) $row->perceptual_hash);
            $percent = round(max(0, (1 - ($distance / self::HASH_BITS)) * 100), 1);

            if ($percent < $minPercent) {
                continue;
            }

            $productId = (int) $row->product_id;
            $existing = $bestByProduct[$productId] ?? null;

            if ($existing && $existing['match_percent'] >= $percent) {
                continue;
            }

            $bestByProduct[$productId] = [
                'product_id' => $productId,
                'name' => $row->product->name,
                'sku' => $row->product->sku,
                'price' => (float) $row->product->price,
                'stock_quantity' => (int) $row->product->stock_quantity,
                'image_url' => StorefrontAssets::url($row->path),
                'match_percent' => $percent,
                'distance' => $distance,
            ];
        }

        $matches = array_values($bestByProduct);
        usort($matches, fn (array $a, array $b) => $b['match_percent'] <=> $a['match_percent']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * Hash a copy of $image so the source remains usable.
     */
    private function hashGdImageCopy(\GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $copy = imagecreatetruecolor($width, $height);

        if ($copy === false) {
            throw new RuntimeException('Could not allocate image buffer.');
        }

        imagecopy($copy, $image, 0, 0, 0, 0, $width, $height);

        return $this->hashGdImage($copy);
    }

    private function hashGdImage(\GdImage $image): string
    {
        $width = 9;
        $height = 8;
        $resized = imagecreatetruecolor($width, $height);

        if ($resized === false) {
            imagedestroy($image);
            throw new RuntimeException('Could not allocate image buffer.');
        }

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($image),
            imagesy($image),
        );
        imagedestroy($image);

        $bits = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width - 1; $x++) {
                $left = $this->grayAt($resized, $x, $y);
                $right = $this->grayAt($resized, $x + 1, $y);
                $bits .= $left > $right ? '1' : '0';
            }
        }

        imagedestroy($resized);

        return sprintf('%016s', base_convert($bits, 2, 16));
    }

    private function downscaleForHash(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $maxEdge = max($width, $height);

        if ($maxEdge <= self::HASH_MAX_EDGE) {
            return $image;
        }

        $scale = self::HASH_MAX_EDGE / $maxEdge;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $scaled = imagescale($image, $newWidth, $newHeight);
        imagedestroy($image);

        if ($scaled === false) {
            throw new RuntimeException('Could not downscale image for hashing.');
        }

        return $scaled;
    }

    /**
     * Center-crop and destroy the source image.
     */
    private function centerCrop(\GdImage $image, float $fraction): \GdImage
    {
        $cropped = $this->centerCropCopy($image, $fraction);
        imagedestroy($image);

        return $cropped;
    }

    /**
     * Center-crop without destroying the source image.
     */
    private function centerCropCopy(\GdImage $image, float $fraction): \GdImage
    {
        $fraction = max(0.1, min(0.95, $fraction));
        $width = imagesx($image);
        $height = imagesy($image);
        $cropWidth = max(1, (int) round($width * $fraction));
        $cropHeight = max(1, (int) round($height * $fraction));
        $x = (int) max(0, ($width - $cropWidth) / 2);
        $y = (int) max(0, ($height - $cropHeight) / 2);

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        if ($cropped === false) {
            throw new RuntimeException('Could not allocate crop buffer.');
        }

        imagecopy($cropped, $image, 0, 0, $x, $y, $cropWidth, $cropHeight);

        return $cropped;
    }

    /**
     * Remove uniform screenshot chrome (status bars, messenger margins, etc.) by
     * scanning rows/columns with low gray variance. Returns null when no trim helps.
     */
    private function trimScreenshotChromeCopy(\GdImage $image): ?\GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return null;
        }

        $top = 0;
        for ($y = 0; $y < $height; $y++) {
            if ($this->rowGrayStdDev($image, $y) >= self::CHROME_LINE_STD_THRESHOLD) {
                $top = $y;
                break;
            }
        }

        $bottom = $height - 1;
        for ($y = $height - 1; $y >= $top; $y--) {
            if ($this->rowGrayStdDev($image, $y) >= self::CHROME_LINE_STD_THRESHOLD) {
                $bottom = $y;
                break;
            }
        }

        $left = 0;
        for ($x = 0; $x < $width; $x++) {
            if ($this->columnGrayStdDev($image, $x) >= self::CHROME_LINE_STD_THRESHOLD) {
                $left = $x;
                break;
            }
        }

        $right = $width - 1;
        for ($x = $width - 1; $x >= $left; $x--) {
            if ($this->columnGrayStdDev($image, $x) >= self::CHROME_LINE_STD_THRESHOLD) {
                $right = $x;
                break;
            }
        }

        $cropWidth = $right - $left + 1;
        $cropHeight = $bottom - $top + 1;

        $trimmedAny = $top > 0
            || $bottom < ($height - 1)
            || $left > 0
            || $right < ($width - 1);

        if (! $trimmedAny) {
            return null;
        }

        if ($cropWidth < max(8, (int) round($width * self::TRIM_MIN_CONTENT_FRACTION))
            || $cropHeight < max(8, (int) round($height * self::TRIM_MIN_CONTENT_FRACTION))) {
            return null;
        }

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        if ($cropped === false) {
            throw new RuntimeException('Could not allocate trim buffer.');
        }

        imagecopy($cropped, $image, 0, 0, $left, $top, $cropWidth, $cropHeight);

        return $cropped;
    }

    private function rowGrayStdDev(\GdImage $image, int $y): float
    {
        $width = imagesx($image);
        $sum = 0.0;
        $sumSq = 0.0;

        for ($x = 0; $x < $width; $x++) {
            $gray = $this->grayAt($image, $x, $y);
            $sum += $gray;
            $sumSq += $gray * $gray;
        }

        $mean = $sum / $width;
        $variance = ($sumSq / $width) - ($mean * $mean);

        return sqrt(max(0.0, $variance));
    }

    private function columnGrayStdDev(\GdImage $image, int $x): float
    {
        $height = imagesy($image);
        $sum = 0.0;
        $sumSq = 0.0;

        for ($y = 0; $y < $height; $y++) {
            $gray = $this->grayAt($image, $x, $y);
            $sum += $gray;
            $sumSq += $gray * $gray;
        }

        $mean = $sum / $height;
        $variance = ($sumSq / $height) - ($mean * $mean);

        return sqrt(max(0.0, $variance));
    }

    private function grayAt(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
    }

    private function localPath(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', preg_replace('#^/public#', '', $path) ?: $path), '/');
        $absolute = public_path($relative);

        return is_file($absolute) ? $absolute : null;
    }
}
