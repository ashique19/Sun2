<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\StorefrontAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductImageHashService
{
    public const HASH_BITS = 64;

    public const MIN_MATCH_PERCENT = 80;

    public const AUTO_MATCH_PERCENT = 90;

    public const TOP_MATCHES = 3;

    public function hashFile(string $absolutePath): string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Image file is not readable: '.$absolutePath);
        }

        $binary = file_get_contents($absolutePath);

        if ($binary === false) {
            throw new RuntimeException('Could not read image file.');
        }

        return $this->hashBinary($binary);
    }

    public function hashUploadedFile(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (! $path) {
            throw new RuntimeException('Uploaded image path is missing.');
        }

        return $this->hashFile($path);
    }

    public function hashBinary(string $binary): string
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

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
