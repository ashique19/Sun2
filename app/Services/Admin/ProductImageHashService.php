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
    public const PHOTO_ROW_BRIGHT_FRACTION = 0.32;

    /**
     * Mean luminance above this (with low variance) is treated as light letterboxing.
     */
    public const CHROME_LIGHT_MEAN_THRESHOLD = 215.0;

    /**
     * Embedded photo panels in FB viewer screenshots rarely exceed this height fraction.
     */
    public const PHOTO_PANEL_MAX_HEIGHT_FRACTION = 0.58;

    /**
     * Row photo-likelihood needed to count as product content.
     */
    public const PHOTO_ROW_LIKELIHOOD_THRESHOLD = 0.22;

    /**
     * Messenger / feed carousel cards need at least this row texture (std-dev).
     */
    public const EMBEDDED_CARD_ROW_STD_THRESHOLD = 22.0;

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

        $this->appendBoundsHashCandidate($candidates, $seenBounds, $image, $this->detectBrightPhotoPanelBounds($image), 'query_photo_panel_vs_catalog_full');
        $this->appendBoundsHashCandidate($candidates, $seenBounds, $image, $this->detectEmbeddedCardBounds($image), 'query_embedded_card_vs_catalog_full');
        $this->appendBoundsHashCandidate($candidates, $seenBounds, $image, $this->detectLightLetterboxBounds($image), 'query_light_letterbox_vs_catalog_full');
        $this->appendBoundsHashCandidate($candidates, $seenBounds, $image, $this->detectUniformChromeBounds($image), 'query_trim_chrome_vs_catalog_full');

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
     * @param  list<array{hash:string,strategy:string}>  $candidates
     * @param  list<string>  $seenBounds
     * @param  array{0:int,1:int,2:int,3:int,4:string}|null  $bounds
     */
    private function appendBoundsHashCandidate(array &$candidates, array &$seenBounds, \GdImage $image, ?array $bounds, string $strategy): void
    {
        if ($bounds === null) {
            return;
        }

        $boundsKey = $this->boundsKey($bounds);
        if (in_array($boundsKey, $seenBounds, true)) {
            return;
        }

        try {
            $hash = $this->hashFromBounds($image, $bounds);

            if ($hash === null) {
                return;
            }

            $seenBounds[] = $boundsKey;
            $candidates[] = [
                'hash' => $hash,
                'strategy' => $strategy,
            ];
        } catch (Throwable $e) {
            Log::debug('Screenshot bounds hash failed.', [
                'strategy' => $strategy,
                'error' => $e->getMessage(),
            ]);
        }
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
            $this->detectEmbeddedCardBounds($image),
            $this->detectLightLetterboxBounds($image),
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

        $rowLikelihoods = [];
        $rowMeans = [];

        for ($y = 0; $y < $height; $y++) {
            $rowLikelihoods[$y] = $this->rowPhotoLikelihood($image, $y);
            [$rowMeans[$y]] = $this->rowGrayStats($image, $y);
        }

        $isPhotoRow = static fn (int $y): bool => $rowLikelihoods[$y] >= self::PHOTO_ROW_LIKELIHOOD_THRESHOLD;

        $run = $this->bestPhotoContentRun($rowLikelihoods, $height);

        if ($run === null || $run['length'] < max(8, (int) round($height * 0.10))) {
            return null;
        }

        if (! $this->hasScreenshotChrome($image, $run['start'], $run['end'])) {
            return null;
        }

        $top = $run['start'];
        $bottom = $run['end'];

        $maxPanelHeight = max(8, (int) round($height * self::PHOTO_PANEL_MAX_HEIGHT_FRACTION));
        if (($bottom - $top + 1) > $maxPanelHeight) {
            $bottom = $top + $maxPanelHeight - 1;
        }

        $segmentMeans = array_slice($rowMeans, $top, $bottom - $top + 1, true);
        $peakMean = max($segmentMeans);
        $dropThreshold = max(self::CHROME_DARK_MEAN_THRESHOLD + 12, $peakMean * 0.58);

        while ($bottom > $top && ($rowMeans[$bottom] < $dropThreshold || $rowLikelihoods[$bottom] < self::PHOTO_ROW_LIKELIHOOD_THRESHOLD * 0.65)) {
            $bottom--;
        }

        [$left, $right] = $this->horizontalContentBounds($image, $top, $bottom);

        if ($left >= $right || $top >= $bottom || ! $this->boundsAreValid($image, $left, $top, $right, $bottom)) {
            return null;
        }

        return [$left, $top, $right, $bottom, 'photo_panel'];
    }

    /**
     * White Facebook feed posts: trim bright status/header and footer chrome.
     *
     * @return array{0:int,1:int,2:int,3:int,4:string}|null
     */
    private function detectLightLetterboxBounds(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $isLightChrome = fn (int $y): bool => $this->isLightChromeLine($image, $y, true);

        $top = 0;
        for ($y = 0; $y < $height; $y++) {
            if (! $isLightChrome($y)) {
                $top = $y;
                break;
            }
        }

        $bottom = $height - 1;
        for ($y = $height - 1; $y >= $top; $y--) {
            if (! $isLightChrome($y)) {
                $bottom = $y;
                break;
            }
        }

        if ($bottom <= $top) {
            return null;
        }

        $contentLikelihoods = [];
        for ($y = $top; $y <= $bottom; $y++) {
            $contentLikelihoods[$y] = $this->rowPhotoLikelihood($image, $y);
        }

        $run = $this->bestPhotoContentRun($contentLikelihoods, $bottom - $top + 1, $top);

        if ($run === null) {
            return null;
        }

        $panelTop = $run['start'];
        $panelBottom = $run['end'];

        $rowLikelihoods = [];
        for ($y = $panelTop; $y <= $panelBottom; $y++) {
            $rowLikelihoods[$y] = $this->rowPhotoLikelihood($image, $y);
        }

        while ($panelBottom > $panelTop && $rowLikelihoods[$panelBottom] < self::PHOTO_ROW_LIKELIHOOD_THRESHOLD * 0.7) {
            $panelBottom--;
        }

        if (($panelBottom - $panelTop + 1) < max(8, (int) round($height * 0.12))) {
            return null;
        }

        if (! $this->hasScreenshotChrome($image, $panelTop, $panelBottom)) {
            return null;
        }

        [$left, $right] = $this->horizontalContentBounds($image, $panelTop, $panelBottom);

        if ($left >= $right || ! $this->boundsAreValid($image, $left, $panelTop, $right, $panelBottom)) {
            return null;
        }

        return [$left, $panelTop, $right, $panelBottom, 'light_letterbox'];
    }

    /**
     * Messenger carousel / ad cards on a dark chat or feed background.
     *
     * @return array{0:int,1:int,2:int,3:int,4:string}|null
     */
    private function detectEmbeddedCardBounds(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $scanTop = (int) round($height * 0.22);
        $scanBottom = (int) round($height * 0.82);

        $rowScores = [];
        for ($y = 0; $y < $height; $y++) {
            [$mean, $std] = $this->rowGrayStats($image, $y);
            $rowScores[$y] = ($std >= self::EMBEDDED_CARD_ROW_STD_THRESHOLD && $mean >= 35 && $mean <= 210)
                ? $this->rowPhotoLikelihood($image, $y)
                : 0.0;
        }

        $bestRun = null;
        $start = null;

        for ($y = $scanTop; $y <= $scanBottom; $y++) {
            if ($rowScores[$y] >= self::PHOTO_ROW_LIKELIHOOD_THRESHOLD) {
                if ($start === null) {
                    $start = $y;
                }

                continue;
            }

            if ($start !== null) {
                $length = $y - $start;
                if ($bestRun === null || $length > $bestRun['length']) {
                    $bestRun = ['start' => $start, 'end' => $y - 1, 'length' => $length];
                }
                $start = null;
            }
        }

        if ($start !== null) {
            $length = min($scanBottom, $height - 1) - $start + 1;
            if ($bestRun === null || $length > $bestRun['length']) {
                $bestRun = ['start' => $start, 'end' => min($scanBottom, $height - 1), 'length' => $length];
            }
        }

        if ($bestRun === null || $bestRun['length'] < max(8, (int) round($height * 0.10))) {
            return null;
        }

        $top = $bestRun['start'];
        $bottom = $bestRun['end'];

        if (! $this->hasDarkMarginsAroundBand($image, $top, $bottom)) {
            return null;
        }

        [$left, $right] = $this->horizontalContentBounds($image, $top, $bottom);

        if ($left >= $right || ! $this->boundsAreValid($image, $left, $top, $right, $bottom)) {
            return null;
        }

        $contentWidthFraction = ($right - $left + 1) / $width;
        if ($contentWidthFraction > 0.88) {
            return null;
        }

        if ($left < (int) round($width * 0.03) && ($width - 1 - $right) < (int) round($width * 0.03)) {
            return null;
        }

        return [$left, $top, $right, $bottom, 'embedded_card'];
    }

    /**
     * @param  array<int, float>  $likelihoods
     * @return array{start:int,end:int,length:int}|null
     */
    private function bestPhotoContentRun(array $likelihoods, int $length, int $offset = 0): ?array
    {
        $best = $this->longestContiguousRun(
            static fn (int $i): bool => ($likelihoods[$offset + $i] ?? 0.0) >= self::PHOTO_ROW_LIKELIHOOD_THRESHOLD,
            $length,
        );

        if ($best === null) {
            return null;
        }

        return [
            'start' => $best['start'] + $offset,
            'end' => $best['end'] + $offset,
            'length' => $best['length'],
        ];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function horizontalContentBounds(\GdImage $image, int $top, int $bottom): array
    {
        $width = imagesx($image);
        $colMeans = [];
        $colBrightFractions = [];
        $colLikelihoods = [];

        for ($x = 0; $x < $width; $x++) {
            [$mean, , $brightFraction] = $this->columnGrayStats($image, $x, $top, $bottom);
            $colMeans[$x] = $mean;
            $colBrightFractions[$x] = $brightFraction;
            $colLikelihoods[$x] = $this->columnPhotoLikelihood($image, $x, $top, $bottom);
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
            if ($colLikelihoods[$x] >= self::PHOTO_ROW_LIKELIHOOD_THRESHOLD
                || ($colBrightFractions[$x] >= self::PHOTO_ROW_BRIGHT_FRACTION && $colMeans[$x] >= $colThreshold)) {
                $left = $x;
                break;
            }
        }

        $right = $width - 1;
        for ($x = $width - 1; $x >= $left; $x--) {
            if ($colLikelihoods[$x] >= self::PHOTO_ROW_LIKELIHOOD_THRESHOLD
                || ($colBrightFractions[$x] >= self::PHOTO_ROW_BRIGHT_FRACTION && $colMeans[$x] >= $colThreshold)) {
                $right = $x;
                break;
            }
        }

        return [$left, $right];
    }

    private function rowPhotoLikelihood(\GdImage $image, int $y): float
    {
        $width = imagesx($image);
        $third = max(1, (int) floor($width / 3));
        $thirdStats = [];

        for ($i = 0; $i < 3; $i++) {
            $x0 = $i * $third;
            $x1 = ($i === 2) ? $width : ($i + 1) * $third;
            $sum = 0.0;
            $bright = 0;
            $count = 0;

            for ($x = $x0; $x < $x1; $x++) {
                $gray = $this->grayAt($image, $x, $y);
                $sum += $gray;
                if ($gray > self::CHROME_DARK_MEAN_THRESHOLD) {
                    $bright++;
                }
                $count++;
            }

            $thirdStats[] = [
                'mean' => $sum / $count,
                'bright' => $bright / $count,
            ];
        }

        $avgBright = array_sum(array_column($thirdStats, 'bright')) / 3;
        $minBright = min(array_column($thirdStats, 'bright'));
        $maxBright = max(array_column($thirdStats, 'bright'));
        $avgMean = array_sum(array_column($thirdStats, 'mean')) / 3;

        if ($avgBright < 0.16) {
            return 0.0;
        }

        if (($maxBright - $minBright) > 0.42) {
            return 0.0;
        }

        $evenness = 1 - min(1.0, ($maxBright - $minBright) / 0.42);
        $brightnessScore = min(1.0, $avgBright / 0.55);
        $meanScore = min(1.0, $avgMean / 70);

        return $brightnessScore * $evenness * max(0.35, $meanScore);
    }

    private function columnPhotoLikelihood(\GdImage $image, int $x, int $top, int $bottom): float
    {
        $span = $bottom - $top + 1;
        $third = max(1, (int) floor($span / 3));
        $thirdStats = [];

        for ($i = 0; $i < 3; $i++) {
            $y0 = $top + ($i * $third);
            $y1 = ($i === 2) ? $bottom : ($top + (($i + 1) * $third) - 1);
            $sum = 0.0;
            $bright = 0;
            $count = 0;

            for ($y = $y0; $y <= $y1; $y++) {
                $gray = $this->grayAt($image, $x, $y);
                $sum += $gray;
                if ($gray > self::CHROME_DARK_MEAN_THRESHOLD) {
                    $bright++;
                }
                $count++;
            }

            $thirdStats[] = [
                'mean' => $sum / $count,
                'bright' => $bright / $count,
            ];
        }

        $avgBright = array_sum(array_column($thirdStats, 'bright')) / 3;
        $minBright = min(array_column($thirdStats, 'bright'));
        $maxBright = max(array_column($thirdStats, 'bright'));

        if ($avgBright < 0.16 || ($maxBright - $minBright) > 0.45) {
            return 0.0;
        }

        $evenness = 1 - min(1.0, ($maxBright - $minBright) / 0.45);

        return min(1.0, $avgBright / 0.5) * $evenness;
    }

    private function hasScreenshotChrome(\GdImage $image, int $panelTop, int $panelBottom): bool
    {
        $height = imagesy($image);
        $darkRowsAbove = 0;
        $darkRowsBelow = 0;
        $lightRowsAbove = 0;
        $lightRowsBelow = 0;

        for ($y = 0; $y < $panelTop; $y++) {
            if ($this->isDarkChromeLine($image, $y, true)) {
                $darkRowsAbove++;
            }
            if ($this->isLightChromeLine($image, $y, true)) {
                $lightRowsAbove++;
            }
        }

        for ($y = $panelBottom + 1; $y < $height; $y++) {
            if ($this->isDarkChromeLine($image, $y, true)) {
                $darkRowsBelow++;
            }
            if ($this->isLightChromeLine($image, $y, true)) {
                $lightRowsBelow++;
            }
        }

        $minBand = max(3, (int) round($height * 0.035));

        return $darkRowsAbove >= $minBand
            || $darkRowsBelow >= $minBand
            || $lightRowsAbove >= $minBand
            || $lightRowsBelow >= $minBand;
    }

    private function hasDarkMarginsAroundBand(\GdImage $image, int $top, int $bottom): bool
    {
        $height = imagesy($image);
        $darkAbove = 0;
        $darkBelow = 0;

        for ($y = max(0, $top - (int) round($height * 0.08)); $y < $top; $y++) {
            if ($this->isDarkChromeLine($image, $y, true)) {
                $darkAbove++;
            }
        }

        for ($y = $bottom + 1; $y <= min($height - 1, $bottom + (int) round($height * 0.08)); $y++) {
            if ($this->isDarkChromeLine($image, $y, true)) {
                $darkBelow++;
            }
        }

        return $darkAbove >= 2 || $darkBelow >= 2;
    }

    private function isDarkChromeLine(\GdImage $image, int $index, bool $isRow): bool
    {
        [$mean, $std] = $isRow
            ? $this->rowGrayStats($image, $index)
            : $this->columnGrayStats($image, $index);

        return $std < self::CHROME_LINE_STD_THRESHOLD + 4
            && $mean <= self::CHROME_DARK_MEAN_THRESHOLD + 8;
    }

    private function isLightChromeLine(\GdImage $image, int $index, bool $isRow): bool
    {
        [$mean, $std] = $isRow
            ? $this->rowGrayStats($image, $index)
            : $this->columnGrayStats($image, $index);

        return $std < self::CHROME_LINE_STD_THRESHOLD + 2
            && $mean >= self::CHROME_LIGHT_MEAN_THRESHOLD;
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
        $width = imagesx($image);
        $widthFraction = ($right - $left + 1) / $width;
        $strategyBonus = match ($strategy) {
            'photo_panel' => 0.12 + ($widthFraction >= 0.82 ? 0.08 : 0.0),
            'embedded_card' => $widthFraction <= 0.86 ? 0.1 : -0.15,
            'light_letterbox' => 0.08,
            default => 0.0,
        };

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
