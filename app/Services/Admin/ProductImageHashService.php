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
     * Mean luminance below this (with low variance) is treated as dark letterboxing.
     */
    public const CHROME_DARK_MEAN_THRESHOLD = 28.0;

    /**
     * Minimum fraction of a row/column that must be brighter than {@see CHROME_DARK_MEAN_THRESHOLD}
     * to count as embedded photo content (vs. a dark Facebook overlay band).
     */
    public const PHOTO_ROW_BRIGHT_FRACTION = 0.42;

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
            $best = null;

            foreach ($this->queryHashesFromImage($image) as $candidate) {
                $matches = $this->findTopMatches($candidate['hash'], 1, self::AUTO_MATCH_PERCENT);
                $top = $matches[0] ?? null;

                if ($top === null) {
                    continue;
                }

                if ($best === null || (float) $top['match_percent'] > (float) $best['match_percent']) {
                    $best = $top + ['strategy' => $candidate['strategy']];
                }
            }

            if ($best !== null && (float) $best['match_percent'] >= self::AUTO_MATCH_PERCENT) {
                return $best;
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
                'strategy' => 'query_full_vs_catalog_full',
            ],
        ];

        $seenBounds = [];

        try {
            $panelBounds = $this->detectBrightPhotoPanelBounds($image);

            if ($panelBounds !== null) {
                $hash = $this->hashFromBounds($image, $panelBounds);

                if ($hash !== null) {
                    $seenBounds[] = $this->boundsKey($panelBounds);
                    $candidates[] = [
                        'hash' => $hash,
                        'strategy' => 'query_photo_panel_vs_catalog_full',
                    ];
                }
            }
        } catch (Throwable $e) {
            Log::debug('Screenshot photo-panel hash failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $trimBounds = $this->detectUniformChromeBounds($image);

            if ($trimBounds !== null) {
                $boundsKey = $this->boundsKey($trimBounds);

                if (! in_array($boundsKey, $seenBounds, true)) {
                    $hash = $this->hashFromBounds($image, $trimBounds);

                    if ($hash !== null) {
                        $seenBounds[] = $boundsKey;
                        $candidates[] = [
                            'hash' => $hash,
                            'strategy' => 'query_trim_chrome_vs_catalog_full',
                        ];
                    }
                }
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

    /**
     * @param  array{0:int,1:int,2:int,3:int,4:string}  $bounds
     */
    private function hashFromBounds(\GdImage $image, array $bounds): ?string
    {
        [$left, $top, $right, $bottom] = $bounds;
        $cropWidth = $right - $left + 1;
        $cropHeight = $bottom - $top + 1;

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        if ($cropped === false) {
            throw new RuntimeException('Could not allocate crop buffer.');
        }

        imagecopy($cropped, $image, 0, 0, $left, $top, $cropWidth, $cropHeight);

        return $this->hashGdImage($cropped);
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int,4:string}  $bounds
     */
    private function boundsKey(array $bounds): string
    {
        return implode(':', array_slice($bounds, 0, 4));
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
     * Suggest a crop box for inbox screenshot tagging as fractions of the image size.
     *
     * @return array{left: float, top: float, width: float, height: float, strategy: string}|null
     */
    public function suggestScreenshotCropFractions(string $binary): ?array
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        try {
            $image = $this->downscaleForHash($image);
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width < 8 || $height < 8) {
                return null;
            }

            $bounds = $this->detectScreenshotContentBounds($image);

            if ($bounds === null) {
                return null;
            }

            [$left, $top, $right, $bottom] = $bounds;
            $cropWidth = $right - $left + 1;
            $cropHeight = $bottom - $top + 1;

            return [
                'left' => round($left / $width, 4),
                'top' => round($top / $height, 4),
                'width' => round($cropWidth / $width, 4),
                'height' => round($cropHeight / $height, 4),
                'strategy' => $bounds[4] ?? 'trim',
            ];
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Pixel bounds [left, top, right, bottom, strategy] for screenshot product content.
     *
     * @return array{0:int,1:int,2:int,3:int,4:string}|null
     */
    private function detectScreenshotContentBounds(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return null;
        }

        $candidates = array_filter([
            $this->detectBrightPhotoPanelBounds($image),
            $this->detectUniformChromeBounds($image),
        ]);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b) use ($image, $width, $height): int {
            $areaA = ($a[2] - $a[0] + 1) * ($a[3] - $a[1] + 1);
            $areaB = ($b[2] - $b[0] + 1) * ($b[3] - $b[1] + 1);
            $fullArea = $width * $height;

            // Prefer tighter crops that still contain a meaningful photo panel.
            $minArea = max(64, (int) round($fullArea * self::TRIM_MIN_CONTENT_FRACTION));
            $scoreA = $this->boundsQualityScore($image, $a, $areaA, $fullArea, $minArea);
            $scoreB = $this->boundsQualityScore($image, $b, $areaB, $fullArea, $minArea);

            return $scoreB <=> $scoreA;
        });

        return $candidates[0];
    }

    /**
     * Facebook / gallery viewer screenshots: find the bright embedded photo between
     * dark letterboxing and the lower UI overlay (profile row, Send message, etc.).
     *
     * @return array{0:int,1:int,2:int,3:int,4:string}|null
     */
    private function detectBrightPhotoPanelBounds(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $rowMeans = [];
        $rowBrightFractions = [];

        for ($y = 0; $y < $height; $y++) {
            [$mean, , $brightFraction] = $this->rowGrayStats($image, $y);
            $rowMeans[$y] = $mean;
            $rowBrightFractions[$y] = $brightFraction;
        }

        $sortedMeans = $rowMeans;
        sort($sortedMeans);
        $p25 = $this->percentile($sortedMeans, 0.25);
        $p75 = $this->percentile($sortedMeans, 0.75);
        $meanThreshold = max(
            self::CHROME_DARK_MEAN_THRESHOLD + 8,
            $p25 + (($p75 - $p25) * 0.5),
        );

        $isPhotoRow = static function (int $y) use ($rowMeans, $rowBrightFractions, $meanThreshold): bool {
            return $rowBrightFractions[$y] >= self::PHOTO_ROW_BRIGHT_FRACTION
                || ($rowMeans[$y] >= $meanThreshold && $rowBrightFractions[$y] >= 0.28);
        };

        $run = $this->longestContiguousRun($isPhotoRow, $height);

        if ($run === null || $run['length'] < max(8, (int) round($height * 0.12))) {
            return null;
        }

        if (! $this->hasLetterboxChrome($image, $run['start'], $run['end'])) {
            return null;
        }

        $top = $run['start'];
        $bottom = $run['end'];

        $segmentMeans = array_slice($rowMeans, $top, $bottom - $top + 1, true);
        $peakMean = max($segmentMeans);
        $dropThreshold = max(self::CHROME_DARK_MEAN_THRESHOLD + 12, $peakMean * 0.62);

        while ($bottom > $top && $rowMeans[$bottom] < $dropThreshold) {
            $bottom--;
        }

        $colMeans = [];
        $colBrightFractions = [];

        for ($x = 0; $x < $width; $x++) {
            [$mean, , $brightFraction] = $this->columnGrayStats($image, $x, $top, $bottom);
            $colMeans[$x] = $mean;
            $colBrightFractions[$x] = $brightFraction;
        }

        $sortedColMeans = $colMeans;
        sort($sortedColMeans);
        $colP25 = $this->percentile($sortedColMeans, 0.25);
        $colP75 = $this->percentile($sortedColMeans, 0.75);
        $colThreshold = max(
            self::CHROME_DARK_MEAN_THRESHOLD + 8,
            $colP25 + (($colP75 - $colP25) * 0.45),
        );

        $left = 0;
        for ($x = 0; $x < $width; $x++) {
            if ($colBrightFractions[$x] >= self::PHOTO_ROW_BRIGHT_FRACTION
                || ($colMeans[$x] >= $colThreshold && $colBrightFractions[$x] >= 0.28)) {
                $left = $x;
                break;
            }
        }

        $right = $width - 1;
        for ($x = $width - 1; $x >= $left; $x--) {
            if ($colBrightFractions[$x] >= self::PHOTO_ROW_BRIGHT_FRACTION
                || ($colMeans[$x] >= $colThreshold && $colBrightFractions[$x] >= 0.28)) {
                $right = $x;
                break;
            }
        }

        if ($left >= $right || $top >= $bottom) {
            return null;
        }

        if (! $this->boundsAreValid($image, $left, $top, $right, $bottom)) {
            return null;
        }

        return [$left, $top, $right, $bottom, 'photo_panel'];
    }

    private function hasLetterboxChrome(\GdImage $image, int $panelTop, int $panelBottom): bool
    {
        $height = imagesy($image);
        $darkRowsAbove = 0;
        $darkRowsBelow = 0;

        for ($y = 0; $y < $panelTop; $y++) {
            [$mean, , $brightFraction] = $this->rowGrayStats($image, $y);
            if ($mean <= self::CHROME_DARK_MEAN_THRESHOLD && $brightFraction < 0.2) {
                $darkRowsAbove++;
            }
        }

        for ($y = $panelBottom + 1; $y < $height; $y++) {
            [$mean, , $brightFraction] = $this->rowGrayStats($image, $y);
            if ($mean <= self::CHROME_DARK_MEAN_THRESHOLD + 6 && $brightFraction < 0.35) {
                $darkRowsBelow++;
            }
        }

        $minBand = max(3, (int) round($height * 0.04));

        return $darkRowsAbove >= $minBand || $darkRowsBelow >= $minBand;
    }

    /**
     * Trim uniform dark / low-variance chrome bands (status bar, black margins).
     *
     * @return array{0:int,1:int,2:int,3:int,4:string}|null
     */
    private function detectUniformChromeBounds(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return null;
        }

        $top = 0;
        for ($y = 0; $y < $height; $y++) {
            if (! $this->isUniformChromeLine($image, $y, true)) {
                $top = $y;
                break;
            }
        }

        $bottom = $height - 1;
        for ($y = $height - 1; $y >= $top; $y--) {
            if (! $this->isUniformChromeLine($image, $y, true)) {
                $bottom = $y;
                break;
            }
        }

        $left = 0;
        for ($x = 0; $x < $width; $x++) {
            if (! $this->isUniformChromeLine($image, $x, false)) {
                $left = $x;
                break;
            }
        }

        $right = $width - 1;
        for ($x = $width - 1; $x >= $left; $x--) {
            if (! $this->isUniformChromeLine($image, $x, false)) {
                $right = $x;
                break;
            }
        }

        $trimmedAny = $top > 0
            || $bottom < ($height - 1)
            || $left > 0
            || $right < ($width - 1);

        if (! $trimmedAny || ! $this->boundsAreValid($image, $left, $top, $right, $bottom)) {
            return null;
        }

        return [$left, $top, $right, $bottom, 'trim'];
    }

    private function boundsAreValid(\GdImage $image, int $left, int $top, int $right, int $bottom): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $cropWidth = $right - $left + 1;
        $cropHeight = $bottom - $top + 1;

        return $cropWidth >= max(8, (int) round($width * self::TRIM_MIN_CONTENT_FRACTION))
            && $cropHeight >= max(8, (int) round($height * self::TRIM_MIN_CONTENT_FRACTION));
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int,4:string}  $bounds
     */
    private function boundsQualityScore(\GdImage $image, array $bounds, int $area, int $fullArea, int $minArea): float
    {
        if ($area < $minArea) {
            return -1.0;
        }

        [$left, $top, $right, $bottom, $strategy] = $bounds;
        $meanSum = 0.0;
        $count = 0;

        for ($y = $top; $y <= $bottom; $y++) {
            [$mean] = $this->rowGrayStats($image, $y);
            $meanSum += $mean;
            $count++;
        }

        $avgMean = $count > 0 ? $meanSum / $count : 0.0;
        $tightness = 1 - ($area / $fullArea);
        $strategyBonus = $strategy === 'photo_panel' ? 0.12 : 0.0;

        return ($avgMean / 255) + $tightness + $strategyBonus;
    }

    private function isUniformChromeLine(\GdImage $image, int $index, bool $isRow): bool
    {
        if ($isRow) {
            [, $std] = $this->rowGrayStats($image, $index);
        } else {
            [, $std] = $this->columnGrayStats($image, $index);
        }

        return $std < self::CHROME_LINE_STD_THRESHOLD;
    }

    /**
     * @param  callable(int): bool  $predicate
     * @return array{start:int,end:int,length:int}|null
     */
    private function longestContiguousRun(callable $predicate, int $length): ?array
    {
        $best = null;
        $start = null;

        for ($i = 0; $i < $length; $i++) {
            if ($predicate($i)) {
                if ($start === null) {
                    $start = $i;
                }

                continue;
            }

            if ($start !== null) {
                $runLength = $i - $start;
                if ($best === null || $runLength > $best['length']) {
                    $best = ['start' => $start, 'end' => $i - 1, 'length' => $runLength];
                }
                $start = null;
            }
        }

        if ($start !== null) {
            $runLength = $length - $start;
            if ($best === null || $runLength > $best['length']) {
                $best = ['start' => $start, 'end' => $length - 1, 'length' => $runLength];
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $sortedValues
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) floor(($count - 1) * $percentile);

        return $sortedValues[$index];
    }

    /**
     * @return array{0: float, 1: float, 2: float} mean, std-dev, bright-pixel fraction
     */
    private function rowGrayStats(\GdImage $image, int $y): array
    {
        $width = imagesx($image);
        $sum = 0.0;
        $sumSq = 0.0;
        $bright = 0;

        for ($x = 0; $x < $width; $x++) {
            $gray = $this->grayAt($image, $x, $y);
            $sum += $gray;
            $sumSq += $gray * $gray;

            if ($gray > self::CHROME_DARK_MEAN_THRESHOLD) {
                $bright++;
            }
        }

        $mean = $sum / $width;
        $variance = ($sumSq / $width) - ($mean * $mean);

        return [$mean, sqrt(max(0.0, $variance)), $bright / $width];
    }

    /**
     * @return array{0: float, 1: float, 2: float} mean, std-dev, bright-pixel fraction
     */
    private function columnGrayStats(\GdImage $image, int $x, ?int $yStart = null, ?int $yEnd = null): array
    {
        $height = imagesy($image);
        $yStart = $yStart ?? 0;
        $yEnd = $yEnd ?? ($height - 1);
        $span = max(1, $yEnd - $yStart + 1);
        $sum = 0.0;
        $sumSq = 0.0;
        $bright = 0;

        for ($y = $yStart; $y <= $yEnd; $y++) {
            $gray = $this->grayAt($image, $x, $y);
            $sum += $gray;
            $sumSq += $gray * $gray;

            if ($gray > self::CHROME_DARK_MEAN_THRESHOLD) {
                $bright++;
            }
        }

        $mean = $sum / $span;
        $variance = ($sumSq / $span) - ($mean * $mean);

        return [$mean, sqrt(max(0.0, $variance)), $bright / $span];
    }

    /**
     * Remove uniform screenshot chrome (status bars, messenger margins, etc.) by
     * scanning rows/columns with low gray variance. Returns null when no trim helps.
     */
    private function trimScreenshotChromeCopy(\GdImage $image): ?\GdImage
    {
        $bounds = $this->detectScreenshotContentBounds($image);

        if ($bounds === null) {
            return null;
        }

        [$left, $top, $right, $bottom] = $bounds;
        $cropWidth = $right - $left + 1;
        $cropHeight = $bottom - $top + 1;

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
