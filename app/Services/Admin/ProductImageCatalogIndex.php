<?php

namespace App\Services\Admin;

use App\Models\ProductImage;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\Cache;

/**
 * One published-catalog snapshot of stored dHash variants, DCT hashes, and embeddings.
 * Inbox matching compares every query crop against this snapshot instead of reloading
 * product_images for each hash.
 */
class ProductImageCatalogIndex
{
    public const CATALOG_EPOCH = 'product-image-catalog-epoch';

    public const MEMORY_EPOCH = 'product-image-memory-epoch';

    private const CACHE_PREFIX = 'product-image-catalog-index:v1:';

    private static ?string $loadedFingerprint = null;

    /** @var array{rows: list<array<string, mixed>>, dhash: list<array{row: int, bits: string}>, dct: list<array{row: int, bits: string}>}|null */
    private static ?array $loaded = null;

    public static function bump(): void
    {
        self::forgetLoaded();
        self::incrementEpoch(self::CATALOG_EPOCH);
    }

    public static function bumpMemory(): void
    {
        self::incrementEpoch(self::MEMORY_EPOCH);
    }

    public static function forgetLoaded(): void
    {
        self::$loadedFingerprint = null;
        self::$loaded = null;
    }

    public function fingerprint(): string
    {
        $stats = ProductImage::query()->toBase()
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(MAX(id), 0) as max_id')
            ->first();

        return ((int) Cache::get(self::CATALOG_EPOCH, 0))
            .'|'.(int) ($stats->aggregate_count ?? 0)
            .'|'.(int) ($stats->max_id ?? 0);
    }

    public function matchCacheFingerprint(): string
    {
        return $this->fingerprint().'|mem:'.((int) Cache::get(self::MEMORY_EPOCH, 0));
    }

    /**
     * Build and store the snapshot. Safe to call after products:index-image-hashes.
     *
     * @return array{rows: list<array<string, mixed>>, dhash: list<array{row: int, bits: string}>, dct: list<array{row: int, bits: string}>}
     */
    public function warm(): array
    {
        return $this->load();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, dhash: list<array{row: int, bits: string}>, dct: list<array{row: int, bits: string}>}
     */
    public function load(): array
    {
        $fingerprint = $this->fingerprint();

        if (self::$loadedFingerprint === $fingerprint && self::$loaded !== null) {
            return self::$loaded;
        }

        $cached = Cache::get(self::CACHE_PREFIX.$fingerprint);
        $rows = is_array($cached) ? $cached : null;

        if ($rows === null) {
            $rows = $this->buildRows();
            Cache::forever(self::CACHE_PREFIX.$fingerprint, $rows);
        }

        self::$loadedFingerprint = $fingerprint;
        self::$loaded = $this->hydrate($rows);

        return self::$loaded;
    }

    /**
     * @return list<array{product_id: int, name: string, sku: ?string, price: float, stock_quantity: int, image_url: ?string, dhashes: list<string>, dct: ?string, embedding: ?list<float>}>
     */
    private function buildRows(): array
    {
        $images = ProductImage::query()
            ->where(function ($query): void {
                $query->whereNotNull('perceptual_hash')
                    ->orWhereNotNull('perceptual_hashes')
                    ->orWhereNotNull('dct_hash')
                    ->orWhereNotNull('embedding_vector');
            })
            ->whereHas('product', fn ($query) => $query->where('is_published', true))
            ->with(['product:id,name,sku,price,stock_quantity,is_published'])
            ->orderBy('id')
            ->get(['id', 'product_id', 'path', 'perceptual_hash', 'perceptual_hashes', 'dct_hash', 'embedding_vector']);

        $rows = [];

        foreach ($images as $image) {
            $product = $image->product;
            if ($product === null || ! $product->is_published) {
                continue;
            }

            $dhashes = [];

            if (is_array($image->perceptual_hashes)) {
                foreach ($image->perceptual_hashes as $variant) {
                    $hash = is_array($variant) ? ($variant['hash'] ?? null) : null;
                    if (is_string($hash) && $hash !== '') {
                        $dhashes[$hash] = $hash;
                    }
                }
            }

            if (is_string($image->perceptual_hash) && $image->perceptual_hash !== '') {
                $dhashes[$image->perceptual_hash] = $image->perceptual_hash;
            }

            $embedding = $image->embedding_vector;
            if (! is_array($embedding) || $embedding === []) {
                $embedding = null;
            }

            $rows[] = [
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku !== null ? (string) $product->sku : null,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => StorefrontAssets::url($image->path),
                'dhashes' => array_values($dhashes),
                'dct' => is_string($image->dct_hash) && $image->dct_hash !== '' ? $image->dct_hash : null,
                'embedding' => $embedding,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, dhash: list<array{row: int, bits: string}>, dct: list<array{row: int, bits: string}>}
     */
    private function hydrate(array $rows): array
    {
        $dhash = [];
        $dct = [];

        foreach ($rows as $index => $row) {
            foreach ($row['dhashes'] ?? [] as $hex) {
                $bits = $this->packHex(is_string($hex) ? $hex : '');
                if ($bits !== null) {
                    $dhash[] = ['row' => $index, 'bits' => $bits];
                }
            }

            $dctHex = $row['dct'] ?? null;
            if (is_string($dctHex)) {
                $bits = $this->packHex($dctHex);
                if ($bits !== null) {
                    $dct[] = ['row' => $index, 'bits' => $bits];
                }
            }
        }

        return [
            'rows' => $rows,
            'dhash' => $dhash,
            'dct' => $dct,
        ];
    }

    private function packHex(string $hex): ?string
    {
        $packed = hex2bin(str_pad($hex, 16, '0', STR_PAD_LEFT));

        return $packed === false ? null : $packed;
    }

    private static function incrementEpoch(string $key): void
    {
        Cache::forever($key, ((int) Cache::get($key, 0)) + 1);
    }
}
