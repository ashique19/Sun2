<?php

namespace App\Services\Sitemap;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\SitemapRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SitemapRebuildService
{
    public const DIRTY_CACHE_KEY = 'sitemap.is_dirty';

    public function __construct(
        private readonly SitemapXmlWriter $writer,
    ) {}

    public function isDirty(): bool
    {
        return (bool) Cache::get(self::DIRTY_CACHE_KEY, false);
    }

    public function markDirty(?string $reason = null): void
    {
        Cache::forever(self::DIRTY_CACHE_KEY, true);

        if ($reason) {
            Cache::forever('sitemap.dirty_reason', $reason);
        }
    }

    public function clearDirty(): void
    {
        Cache::forget(self::DIRTY_CACHE_KEY);
        Cache::forget('sitemap.dirty_reason');
    }

    public function dirtyReason(): ?string
    {
        return Cache::get('sitemap.dirty_reason');
    }

    public function latestRun(): ?SitemapRun
    {
        return SitemapRun::query()->latest('id')->first();
    }

    public function activeRun(): ?SitemapRun
    {
        return SitemapRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();
    }

    public function indexExists(): bool
    {
        return Storage::disk('local')->exists($this->writer->indexRelativePath());
    }

    public function start(string $trigger, ?User $user = null, bool $force = false): SitemapRun
    {
        if ($active = $this->activeRun()) {
            if (! $force) {
                $this->markDirty('Rebuild requested while another run was active');

                return $active;
            }

            $active->update([
                'status' => 'failed',
                'message' => 'Superseded by a new rebuild',
                'error' => 'Superseded by a new rebuild',
                'finished_at' => now(),
            ]);
        }

        $productCount = Product::query()->published()->count();
        $categoryCount = Category::query()->where('is_active', true)->count();
        $pageCount = Page::query()->count();
        $staticUrls = 1 + $categoryCount + $pageCount;
        $totalUrls = $staticUrls + $productCount;

        return SitemapRun::query()->create([
            'status' => 'pending',
            'trigger' => $trigger,
            'triggered_by_user_id' => $user?->id,
            'phase' => 'queued',
            'message' => 'Waiting to start…',
            'progress_current' => 0,
            'progress_total' => $totalUrls,
            'urls_written' => 0,
            'product_cursor' => 0,
            'product_chunk_index' => 0,
            'meta' => [
                'product_count' => $productCount,
                'category_count' => $categoryCount,
                'page_count' => $pageCount,
                'static_urls' => $staticUrls,
                'product_files' => [],
            ],
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * Process one chunk. Returns true when the run is finished (completed or failed).
     */
    public function processChunk(SitemapRun $run): bool
    {
        if (in_array($run->status, ['completed', 'failed'], true)) {
            return true;
        }

        try {
            if ($run->status === 'pending') {
                $this->beginRun($run);
            }

            return match ($run->phase) {
                'pages' => $this->writePagesPhase($run),
                'products' => $this->writeProductsPhase($run),
                'index' => $this->writeIndexPhase($run),
                default => $this->fail($run, 'Unknown phase: '.(string) $run->phase),
            };
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }
    }

    /**
     * Run to completion in the current request (secret URL / auto rebuild).
     */
    public function runToCompletion(SitemapRun $run, int $maxChunks = 10_000): SitemapRun
    {
        $chunks = 0;

        while ($chunks < $maxChunks) {
            $chunks++;

            if ($this->processChunk($run->fresh())) {
                break;
            }
        }

        return $run->fresh();
    }

    /**
     * Mark dirty and rebuild now when debounce allows (no queue worker needed).
     */
    public function requestAutoRebuild(string $reason): void
    {
        $this->markDirty($reason);

        if ($this->activeRun()) {
            return;
        }

        $debounce = (int) config('sitemap.auto_rebuild_debounce_seconds', 120);
        $cacheKey = 'sitemap.auto_rebuild_lock';

        if (! Cache::add($cacheKey, true, $debounce)) {
            return;
        }

        $run = $this->start('product');
        $this->runToCompletion($run);
    }

    /**
     * If sitemap is missing or dirty, rebuild synchronously (first crawl / stale file).
     */
    public function ensureFresh(string $trigger = 'lazy'): void
    {
        if ($this->activeRun()) {
            return;
        }

        if ($this->indexExists() && ! $this->isDirty()) {
            return;
        }

        $run = $this->start($trigger);
        $this->runToCompletion($run);
    }

    private function beginRun(SitemapRun $run): void
    {
        $this->writer->prepareDirectory();

        $run->update([
            'status' => 'running',
            'phase' => 'pages',
            'message' => 'Writing pages & categories…',
            'started_at' => now(),
        ]);
    }

    private function writePagesPhase(SitemapRun $run): bool
    {
        $urls = [];

        $urls[] = [
            'loc' => route('home'),
            'lastmod' => now()->toAtomString(),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        Category::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function (Category $category) use (&$urls) {
                $urls[] = [
                    'loc' => route('category.show', $category),
                    'lastmod' => $category->updated_at?->toAtomString(),
                    'changefreq' => 'weekly',
                    'priority' => '0.8',
                ];
            });

        Page::query()
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function (Page $page) use (&$urls) {
                $urls[] = [
                    'loc' => route('page.show', $page),
                    'lastmod' => $page->updated_at?->toAtomString(),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ];
            });

        $this->writer->writeUrlset('pages.xml', $urls);

        $meta = $run->meta ?? [];
        $meta['pages_file'] = 'pages.xml';
        $count = count($urls);

        $run->update([
            'phase' => 'products',
            'message' => 'Writing products…',
            'progress_current' => $count,
            'urls_written' => $count,
            'product_cursor' => 0,
            'product_chunk_index' => 0,
            'meta' => $meta,
        ]);

        return false;
    }

    private function writeProductsPhase(SitemapRun $run): bool
    {
        $chunkSize = (int) config('sitemap.chunk_size', 250);
        $perFile = (int) config('sitemap.products_per_file', 5000);
        $cursor = (int) $run->product_cursor;
        $fileIndex = (int) $run->product_chunk_index;
        $meta = $run->meta ?? [];
        $productFiles = $meta['product_files'] ?? [];

        $products = Product::query()
            ->published()
            ->orderBy('id')
            ->where('id', '>', $cursor)
            ->limit($chunkSize)
            ->get(['id', 'slug', 'updated_at']);

        if ($products->isEmpty()) {
            if ($productFiles === [] && ((int) ($meta['product_count'] ?? 0)) === 0) {
                // Touch empty products file so index is consistent.
                $this->writer->writeUrlset('products-1.xml', []);
                $productFiles[] = 'products-1.xml';
            }

            $meta['product_files'] = $productFiles;

            $run->update([
                'phase' => 'index',
                'message' => 'Writing sitemap index…',
                'meta' => $meta,
            ]);

            return false;
        }

        $bufferKey = 'sitemap.run.'.$run->id.'.buffer.'.$fileIndex;
        $buffer = Cache::get($bufferKey, []);

        foreach ($products as $product) {
            $buffer[] = [
                'loc' => route('product.show', $product),
                'lastmod' => $product->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
            $cursor = (int) $product->id;
        }

        $urlsWritten = (int) $run->urls_written + $products->count();
        $progress = (int) $run->progress_current + $products->count();

        if (count($buffer) >= $perFile) {
            $filename = 'products-'.($fileIndex + 1).'.xml';
            $this->writer->writeUrlset($filename, $buffer);
            $productFiles[] = $filename;
            Cache::forget($bufferKey);
            $fileIndex++;
            $buffer = [];
        } else {
            Cache::put($bufferKey, $buffer, now()->addHour());
        }

        $hasMore = Product::query()->published()->where('id', '>', $cursor)->exists();

        if (! $hasMore && $buffer !== []) {
            $filename = 'products-'.($fileIndex + 1).'.xml';
            $this->writer->writeUrlset($filename, $buffer);
            $productFiles[] = $filename;
            Cache::forget($bufferKey);
            $fileIndex++;
        }

        $meta['product_files'] = $productFiles;

        $run->update([
            'message' => $hasMore
                ? 'Writing products… '.$progress.' / '.$run->progress_total
                : 'Finishing product files…',
            'progress_current' => min($progress, (int) $run->progress_total),
            'urls_written' => $urlsWritten,
            'product_cursor' => $cursor,
            'product_chunk_index' => $fileIndex,
            'meta' => $meta,
            'phase' => $hasMore ? 'products' : 'index',
        ]);

        return false;
    }

    private function writeIndexPhase(SitemapRun $run): bool
    {
        $meta = $run->meta ?? [];
        $children = array_values(array_filter([
            $meta['pages_file'] ?? 'pages.xml',
            ...($meta['product_files'] ?? []),
        ]));

        $this->writer->writeIndex($children);
        $this->writer->pruneStaleProductFiles($meta['product_files'] ?? []);

        $run->update([
            'status' => 'completed',
            'phase' => 'done',
            'message' => 'Sitemap ready ('.$run->urls_written.' URLs)',
            'progress_current' => (int) $run->progress_total,
            'finished_at' => now(),
            'meta' => array_merge($meta, [
                'index_file' => 'sitemap.xml',
                'public_url' => url('/sitemap.xml'),
                'files' => $children,
            ]),
        ]);

        $this->clearDirty();

        return true;
    }

    private function fail(SitemapRun $run, string $error): bool
    {
        $run->update([
            'status' => 'failed',
            'message' => 'Rebuild failed',
            'error' => $error,
            'finished_at' => now(),
        ]);

        $this->markDirty('Previous rebuild failed');

        return true;
    }
}
