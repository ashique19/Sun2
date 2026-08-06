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
     * Center-crop fractions tried when full-frame match is below auto threshold.
     * Order: larger crop first (keeps more product), then tighter screenshot ROIs.
     *
     * @var list<float>
     */
    public const CENTER_CROP_SCALES = [0.7, 0.5, 0.4];

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
     * Plan B: if below auto threshold, compare center crops (query vs each catalog image).
     *
     * @return array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int,strategy:string}|null
     */
    public function findBestAutoMatchFromBinary(string $binary): ?array
    {
        $fullHash = $this->hashBinary($binary);
        $fullMatches = $this->findTopMatches($fullHash, 1, self::AUTO_MATCH_PERCENT);
        $top = $fullMatches[0] ?? null;

        if ($top !== null && (float) $top['match_percent'] >= self::AUTO_MATCH_PERCENT) {
            return $top + ['strategy' => 'full'];
        }

        $best = null;

        foreach (self::CENTER_CROP_SCALES as $scale) {
            try {
                $queryHash = $this->hashBinary($binary, $scale);

                // Screenshot chrome is usually on the edges: compare the cropped
                // query against stored full-frame catalog hashes first.
                $againstFull = $this->findTopMatches($queryHash, 1, self::AUTO_MATCH_PERCENT);
                $this->considerAutoCandidate(
                    $best,
                    $againstFull[0] ?? null,
                    'query_center_'.$this->scaleLabel($scale).'_vs_catalog_full',
                );

                // Also try same center crop on both sides (centered product photos).
                $againstCrop = $this->findTopMatchesAgainstCenterCrop(
                    $queryHash,
                    $scale,
                    1,
                    self::AUTO_MATCH_PERCENT,
                );
                $this->considerAutoCandidate(
                    $best,
                    $againstCrop[0] ?? null,
                    'center_'.$this->scaleLabel($scale),
                );
            } catch (Throwable $e) {
                Log::debug('Center-crop product image match failed.', [
                    'scale' => $scale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $best;
    }

    /**
     * @param  array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int,strategy?:string}|null  $best
     * @param  array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}|null  $candidate
     */
    private function considerAutoCandidate(?array &$best, ?array $candidate, string $strategy): void
    {
        if ($candidate === null || (float) $candidate['match_percent'] < self::AUTO_MATCH_PERCENT) {
            return;
        }

        if ($best !== null && (float) $candidate['match_percent'] <= (float) $best['match_percent']) {
            return;
        }

        $best = $candidate + ['strategy' => $strategy];
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
     * Compare a query center-crop hash to the same center crop of each catalog image.
     *
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    public function findTopMatchesAgainstCenterCrop(
        string $queryHash,
        float $centerFraction,
        int $limit = self::TOP_MATCHES,
        float $minPercent = self::MIN_MATCH_PERCENT,
    ): array {
        $rows = ProductImage::query()
            ->whereNotNull('perceptual_hash')
            ->with(['product:id,name,sku,price,stock_quantity,slug'])
            ->get(['id', 'product_id', 'path', 'perceptual_hash']);

        $bestByProduct = [];

        foreach ($rows as $row) {
            if (! $row->product) {
                continue;
            }

            $catalogHash = $this->hashProductImageCenterCrop($row, $centerFraction);
            if ($catalogHash === null) {
                continue;
            }

            $distance = $this->hammingDistance($queryHash, $catalogHash);
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

    private function hashProductImageCenterCrop(ProductImage $image, float $centerFraction): ?string
    {
        $local = $this->localPath($image->path);

        if ($local) {
            try {
                return $this->hashFile($local, $centerFraction);
            } catch (Throwable) {
                return null;
            }
        }

        $url = StorefrontAssets::url($image->path);
        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful() || $response->body() === '') {
                return null;
            }

            return $this->hashBinary($response->body(), $centerFraction);
        } catch (Throwable) {
            return null;
        }
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

    private function centerCrop(\GdImage $image, float $fraction): \GdImage
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
            imagedestroy($image);
            throw new RuntimeException('Could not allocate crop buffer.');
        }

        imagecopy($cropped, $image, 0, 0, $x, $y, $cropWidth, $cropHeight);
        imagedestroy($image);

        return $cropped;
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
