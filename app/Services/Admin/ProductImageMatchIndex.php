<?php

namespace App\Services\Admin;

use App\Models\ProductImage;
use App\Models\ProductImageMatchMemory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Compiled, file-cached index of catalog + staff-learned signatures.
 *
 * Loading the whole catalog from the DB on every query was the main cost of the old
 * matcher (one full product_images scan per candidate hash). The compiled index is
 * rebuilt only when the cheap stamp query changes, and memoized per PHP process.
 */
class ProductImageMatchIndex
{
    /**
     * @var array{stamp: string, entries: list<array{product_id:int, source:string, source_id:int, variant:string, coarse:list<float>, chroma:list<float>, gray:string, rgb:string}>}|null
     */
    private static ?array $memo = null;

    /** Bump when the compiled entry layout changes (invalidates cached index files). */
    private const FORMAT = 2;

    private const UNREADABLE_CACHE_KEY = 'image-match-signature-unreadable';

    private const WRITE_FAILED_CACHE_KEY = 'image-match-index-write-failed';

    public function __construct(
        private ProductImageSignatureService $signatures,
    ) {}

    /**
     * @return list<array{product_id:int, source:string, source_id:int, variant:string, coarse:list<float>, chroma:list<float>, gray:string, rgb:string}>
     */
    public function entries(): array
    {
        $this->backfillMissingSignatures();

        $stamp = $this->stamp();

        if (self::$memo !== null && self::$memo['stamp'] === $stamp) {
            return self::$memo['entries'];
        }

        $path = self::path();
        if (is_file($path)) {
            try {
                $cached = unserialize((string) file_get_contents($path), ['allowed_classes' => false]);
                if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && is_array($cached['entries'] ?? null)) {
                    self::$memo = $cached;

                    return $cached['entries'];
                }
            } catch (Throwable $e) {
                Log::debug('Image match index cache unreadable; rebuilding.', ['error' => $e->getMessage()]);
            }
        }

        $compiled = ['stamp' => $stamp, 'entries' => $this->build()];
        $this->write($path, $compiled);
        self::$memo = $compiled;

        return $compiled['entries'];
    }

    /**
     * Drop the compiled index (this process + cached file). Other processes notice via the stamp.
     */
    public static function forget(): void
    {
        self::$memo = null;
        File::delete(self::path());
    }

    /**
     * Sign catalog images that have no visual signature yet, within a time budget, so matching
     * works before `products:index-image-hashes` has been run. One process at a time; images
     * that cannot be read are skipped for a day.
     *
     * @return int images signed
     */
    public function backfillMissingSignatures(?int $budgetMs = null): int
    {
        $budgetMs ??= (int) config('channels.image_match.lazy_index_budget_ms', 1500);
        if ($budgetMs <= 0) {
            return 0;
        }

        $unreadable = $this->unreadableIds();
        $pending = ProductImage::query()
            ->whereNull('match_signature')
            ->when($unreadable !== [], fn ($query) => $query->whereNotIn('id', $unreadable));

        if (! $pending->exists()) {
            return 0;
        }

        try {
            $lock = Cache::lock('image-match-signature-backfill', 120);
            if (! $lock->get()) {
                return 0;
            }
        } catch (Throwable $e) {
            Log::warning('Lazy match signature backfill skipped: cache lock unavailable.', ['error' => $e->getMessage()]);

            return 0;
        }

        $signed = 0;
        $deadline = microtime(true) + ($budgetMs / 1000);
        $hasher = app(ProductImageHashService::class);

        try {
            foreach ($pending->select(['id', 'path'])->lazyById(50) as $image) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                try {
                    $stored = $hasher->storeMatchSignature($image);
                } catch (Throwable $e) {
                    Log::debug('Lazy match signature failed.', ['product_image_id' => $image->id, 'error' => $e->getMessage()]);
                    $stored = false;
                }

                if ($stored) {
                    $signed++;
                } else {
                    $unreadable[] = (int) $image->id;
                }
            }
        } finally {
            try {
                Cache::put(self::UNREADABLE_CACHE_KEY, array_values(array_unique($unreadable)), now()->addDay());
                $lock->release();
            } catch (Throwable) {
            }
        }

        return $signed;
    }

    /**
     * Skip a catalog image that could not be read or signed (for a day) in lazy backfill and
     * the coverage counts, so one broken file does not keep the index "incomplete".
     */
    public function markUnreadable(int $productImageId): void
    {
        try {
            Cache::put(
                self::UNREADABLE_CACHE_KEY,
                array_values(array_unique([...$this->unreadableIds(), $productImageId])),
                now()->addDay(),
            );
        } catch (Throwable) {
        }
    }

    /**
     * What admins need to know when inbox matching is slow or failing.
     *
     * @return array{total: int, signed: int, unreadable: int, missing: int, cache_writable: bool, index_write_failed: bool}
     */
    public function health(): array
    {
        $total = ProductImage::query()->count();
        $signed = ProductImage::query()->whereNotNull('match_signature')->count();
        $unreadable = count($this->unreadableIds());

        try {
            $writeFailed = (bool) Cache::get(self::WRITE_FAILED_CACHE_KEY, false);
        } catch (Throwable) {
            $writeFailed = false;
        }

        $directory = dirname(self::path());

        return [
            'total' => $total,
            'signed' => $signed,
            'unreadable' => $unreadable,
            'missing' => max(0, $total - $signed - $unreadable),
            'cache_writable' => is_dir($directory) ? is_writable($directory) : is_writable(dirname($directory)),
            'index_write_failed' => $writeFailed,
        ];
    }

    /**
     * @return list<int>
     */
    private function unreadableIds(): array
    {
        try {
            $ids = Cache::get(self::UNREADABLE_CACHE_KEY, []);
        } catch (Throwable) {
            return [];
        }

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * Cheap fingerprint of everything the index depends on.
     */
    public function stamp(): string
    {
        $images = ProductImage::query()
            ->whereNotNull('match_signature')
            ->selectRaw('COUNT(*) as aggregate_count, MAX(id) as max_id, MAX(updated_at) as max_updated')
            ->first();

        $memories = ProductImageMatchMemory::query()
            ->whereNotNull('signature')
            ->selectRaw('COUNT(*) as aggregate_count, MAX(id) as max_id, MAX(updated_at) as max_updated')
            ->first();

        return implode('|', [
            ProductImageSignatureService::VERSION,
            (int) ($images?->aggregate_count ?? 0),
            (int) ($images?->max_id ?? 0),
            (string) ($images?->max_updated ?? ''),
            (int) ($memories?->aggregate_count ?? 0),
            (int) ($memories?->max_id ?? 0),
            (string) ($memories?->max_updated ?? ''),
        ]);
    }

    /**
     * @return list<array{product_id:int, source:string, source_id:int, variant:string, coarse:list<float>, chroma:list<float>, gray:string, rgb:string}>
     */
    private function build(): array
    {
        $entries = [];

        ProductImage::query()
            ->whereNotNull('match_signature')
            ->select(['id', 'product_id', 'match_signature'])
            ->orderBy('id')
            ->chunkById(500, function ($images) use (&$entries): void {
                foreach ($images as $image) {
                    foreach ($this->signatures->unpack($image->match_signature) as $signature) {
                        $entries[] = $this->entry((int) $image->product_id, 'catalog', (int) $image->id, $signature);
                    }
                }
            });

        ProductImageMatchMemory::query()
            ->whereNotNull('signature')
            ->select(['id', 'product_id', 'signature'])
            ->orderBy('id')
            ->chunkById(500, function ($memories) use (&$entries): void {
                foreach ($memories as $memory) {
                    foreach ($this->signatures->unpack($memory->signature) as $signature) {
                        $entries[] = $this->entry((int) $memory->product_id, 'memory', (int) $memory->id, $signature);
                    }
                }
            });

        return $entries;
    }

    /**
     * @param  array{variant: string, gray: string, color: string}  $signature
     * @return array{product_id:int, source:string, source_id:int, variant:string, coarse:list<float>, chroma:list<float>, gray:string, rgb:string}
     */
    private function entry(int $productId, string $source, int $sourceId, array $signature): array
    {
        return [
            'product_id' => $productId,
            'source' => $source,
            'source_id' => $sourceId,
            'variant' => $signature['variant'],
            'coarse' => $this->signatures->grayVector($signature['gray'], 8),
            'chroma' => $this->signatures->chromaVector($signature['color']),
            'gray' => $signature['gray'],
            'rgb' => $signature['color'],
        ];
    }

    /**
     * @param  array<string, mixed>  $compiled
     */
    private function write(string $path, array $compiled): void
    {
        try {
            File::ensureDirectoryExists(dirname($path));
            $temporary = $path.'.'.getmypid().'.tmp';
            if (File::put($temporary, serialize($compiled)) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not write '.$path);
            }
            Cache::forget(self::WRITE_FAILED_CACHE_KEY);
        } catch (Throwable $e) {
            Log::warning('Image match index cache write failed.', ['error' => $e->getMessage()]);
            try {
                Cache::put(self::WRITE_FAILED_CACHE_KEY, true, now()->addHour());
            } catch (Throwable) {
            }
        }
    }

    private static function path(): string
    {
        return storage_path('framework/cache/image-match-index-v'.ProductImageSignatureService::VERSION.'-'.self::FORMAT.'.bin');
    }
}
