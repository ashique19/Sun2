<?php

namespace App\Services\Admin;

use App\Models\ImageHashRun;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductImageHashRebuildService
{
    public function __construct(
        private readonly ProductImageHashService $hasher,
        private readonly ProductImageSignatureService $signatures,
    ) {}

    public function isFullyIndexed(ProductImage $image): bool
    {
        return is_string($image->perceptual_hash)
            && $image->perceptual_hash !== ''
            && is_array($image->perceptual_hashes)
            && $image->perceptual_hashes !== []
            && is_string($image->dct_hash)
            && $image->dct_hash !== ''
            && is_array($image->embedding_vector)
            && count($image->embedding_vector) === ProductImageEmbeddingService::DIMENSIONS
            && $this->signatures->isCurrent($image->match_signature);
    }

    /**
     * @return Builder<ProductImage>
     */
    public function incompleteIndexQuery(): Builder
    {
        $dimensions = ProductImageEmbeddingService::DIMENSIONS;

        return ProductImage::query()->where(function ($builder) use ($dimensions): void {
            $builder->where(function ($query): void {
                $query->whereNull('perceptual_hash')
                    ->orWhere('perceptual_hash', '');
            })
                ->orWhere(function ($query): void {
                    $query->whereNull('perceptual_hashes')
                        ->orWhereJsonLength('perceptual_hashes', 0);
                })
                ->orWhere(function ($query): void {
                    $query->whereNull('dct_hash')
                        ->orWhere('dct_hash', '');
                })
                ->orWhere(function ($query) use ($dimensions): void {
                    $query->whereNull('embedding_vector')
                        ->orWhereJsonLength('embedding_vector', '<', $dimensions);
                })
                ->orWhereNull('match_signature');
        });
    }

    public function latestRun(): ?ImageHashRun
    {
        return ImageHashRun::query()->latest('id')->first();
    }

    public function activeRun(): ?ImageHashRun
    {
        return ImageHashRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();
    }

    /**
     * @return array{
     *     total:int,
     *     hashed:int,
     *     missing:int,
     *     multi_hashed:int,
     *     missing_variants:int,
     *     fully_indexed:int,
     *     missing_dct:int,
     *     missing_embedding:int,
     *     missing_signature:int,
     *     needs_screenshot_backfill:int
     * }
     */
    public function coverage(): array
    {
        $total = ProductImage::query()->count();
        $hashed = ProductImage::query()->whereNotNull('perceptual_hash')->count();
        $multiHashed = ProductImage::query()->whereNotNull('perceptual_hashes')->count();
        $missingDct = ProductImage::query()->whereNull('dct_hash')->count();
        $missingEmbedding = ProductImage::query()->whereNull('embedding_vector')->count();
        $missingSignature = ProductImage::query()->whereNull('match_signature')->count();
        $needsBackfill = $this->incompleteIndexQuery()->count();
        $fullyIndexed = max(0, $total - $needsBackfill);

        return [
            'total' => $total,
            'hashed' => $hashed,
            'missing' => max(0, $total - $hashed),
            'multi_hashed' => $multiHashed,
            'missing_variants' => max(0, $total - $multiHashed),
            'fully_indexed' => $fullyIndexed,
            'missing_dct' => $missingDct,
            'missing_embedding' => $missingEmbedding,
            'missing_signature' => $missingSignature,
            'needs_screenshot_backfill' => $needsBackfill,
        ];
    }

    public function start(string $trigger, ?User $user = null, bool $force = false, bool $supersede = false): ImageHashRun
    {
        if ($active = $this->activeRun()) {
            if (! $supersede) {
                return $active;
            }

            $active->update([
                'status' => 'failed',
                'message' => 'Superseded by a new rebuild',
                'error' => 'Superseded by a new rebuild',
                'finished_at' => now(),
            ]);
        }

        $query = ProductImage::query();

        if (! $force) {
            $query = $this->incompleteIndexQuery();
        }

        $total = $query->count();

        return ImageHashRun::query()->create([
            'status' => 'pending',
            'trigger' => $trigger,
            'triggered_by_user_id' => $user?->id,
            'force' => $force,
            'phase' => 'queued',
            'message' => 'Waiting to start…',
            'progress_current' => 0,
            'progress_total' => $total,
            'hashed_ok' => 0,
            'failed' => 0,
            'image_cursor' => 0,
            'meta' => [
                'chunk_size' => (int) config('products.image_hash_chunk_size', 25),
            ],
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * Process one chunk (one HTTP tick from the admin page or cron URL). Returns true when finished.
     *
     * Safe under shared-hosting PHP limits: the tick stops before its time budget (derived
     * from max_execution_time), and the image being hashed is recorded first, so if a tick
     * is killed mid-image the next tick skips that image instead of dying on it again.
     */
    public function processChunk(ImageHashRun $run): bool
    {
        if (in_array($run->status, ['completed', 'failed'], true)) {
            return true;
        }

        $lock = null;
        try {
            $lock = Cache::lock('image-hash-rebuild-tick', self::tickBudgetSeconds() + 60);
            if (! $lock->get()) {
                return false;
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            return $this->processChunkLocked($run);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Seconds one tick may spend hashing: well inside max_execution_time (min 2s, max 8s).
     */
    public static function tickBudgetSeconds(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? max(2, min(8, (int) floor($limit * 0.3))) : 8;
    }

    private function processChunkLocked(ImageHashRun $run): bool
    {
        $run->refresh();
        if (in_array($run->status, ['completed', 'failed'], true)) {
            return true;
        }

        try {
            if ($run->status === 'pending') {
                if ((int) $run->progress_total === 0) {
                    $run->update([
                        'status' => 'completed',
                        'phase' => 'done',
                        'message' => 'Nothing to index',
                        'started_at' => now(),
                        'finished_at' => now(),
                        'progress_current' => 0,
                    ]);
                    $this->afterRebuild();

                    return true;
                }

                $run->update([
                    'status' => 'running',
                    'phase' => 'hashing',
                    'message' => 'Hashing product images…',
                    'started_at' => now(),
                ]);
            }

            $chunkSize = (int) ($run->meta['chunk_size'] ?? config('products.image_hash_chunk_size', 5));
            $cursor = (int) $run->image_cursor;
            $chunkDeadline = microtime(true) + self::tickBudgetSeconds();

            $ok = (int) $run->hashed_ok;
            $failed = (int) $run->failed;
            $processed = (int) $run->progress_current;
            $meta = is_array($run->meta) ? $run->meta : [];

            // The previous tick was killed while hashing this image: count it as failed and move on.
            $crashedId = (int) ($meta['in_flight_id'] ?? 0);
            if ($crashedId > $cursor) {
                $cursor = $crashedId;
                $processed++;
                $failed++;
                $this->matchIndex()->markUnreadable($crashedId);
            }
            unset($meta['in_flight_id']);

            if (! $run->force) {
                $query = $this->incompleteIndexQuery()->where('id', '>', $cursor);
            } else {
                $query = ProductImage::query()->where('id', '>', $cursor);
            }

            $images = $query->orderBy('id')->limit($chunkSize)->get();

            if ($images->isEmpty()) {
                $stillIncomplete = ! $run->force && $this->incompleteIndexQuery()->exists();

                $run->update([
                    'status' => 'completed',
                    'phase' => 'done',
                    'message' => sprintf(
                        'Done — hashed %s, failed %s',
                        number_format($ok),
                        number_format($failed),
                    ),
                    'error' => $stillIncomplete && $failed > 0
                        ? sprintf('%s image(s) could not be read (missing file or unreachable URL) and were skipped.', number_format($failed))
                        : null,
                    'image_cursor' => $cursor,
                    'hashed_ok' => $ok,
                    'failed' => $failed,
                    'meta' => $meta,
                    'progress_current' => (int) $run->progress_total,
                    'finished_at' => now(),
                ]);
                $this->afterRebuild();

                return true;
            }

            $lastId = $cursor;

            foreach ($images as $image) {
                if (microtime(true) >= $chunkDeadline) {
                    break;
                }

                $run->forceFill(['meta' => [...$meta, 'in_flight_id' => (int) $image->id]])->save();

                $lastId = (int) $image->id;
                $processed++;

                try {
                    if (! $run->force && $this->isFullyIndexed($image)) {
                        continue;
                    }

                    $stored = ! $run->force && $this->onlyMissingSignature($image)
                        ? $this->hasher->storeMatchSignature($image, allowRemoteDownload: true)
                        : $this->hasher->storeHash($image, allowRemoteDownload: true) !== null;

                    if ($stored) {
                        $ok++;
                    } else {
                        $failed++;
                        $this->matchIndex()->markUnreadable((int) $image->id);
                    }
                } catch (Throwable) {
                    $failed++;
                    $this->matchIndex()->markUnreadable((int) $image->id);
                }
            }

            $run->update([
                'image_cursor' => $lastId,
                'progress_current' => min($processed, (int) $run->progress_total),
                'hashed_ok' => $ok,
                'failed' => $failed,
                'meta' => $meta,
                'message' => sprintf(
                    'Hashing… %s / %s (ok %s, failed %s)',
                    number_format(min($processed, (int) $run->progress_total)),
                    number_format((int) $run->progress_total),
                    number_format($ok),
                    number_format($failed),
                ),
            ]);

            $hasMoreByCursor = $run->force
                ? ProductImage::query()->where('id', '>', $lastId)->exists()
                : $this->incompleteIndexQuery()->where('id', '>', $lastId)->exists();

            if (! $hasMoreByCursor) {
                $run->update([
                    'status' => 'completed',
                    'phase' => 'done',
                    'message' => sprintf(
                        'Done — hashed %s, failed %s',
                        number_format($ok),
                        number_format($failed),
                    ),
                    'error' => $failed > 0
                        ? sprintf('%s image(s) could not be read (missing file or unreachable URL) and were skipped.', number_format($failed))
                        : null,
                    'progress_current' => (int) $run->progress_total,
                    'finished_at' => now(),
                ]);
                $this->afterRebuild();

                return true;
            }

            return false;
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'phase' => 'failed',
                'message' => 'Rebuild failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return true;
        }
    }

    /**
     * Run ticks for up to $seconds (cron URL), leaving the rest for the next call.
     */
    public function runFor(ImageHashRun $run, float $seconds): ImageHashRun
    {
        $deadline = microtime(true) + $seconds;

        do {
            if ($this->processChunk($run->fresh())) {
                break;
            }
        } while (microtime(true) + self::tickBudgetSeconds() < $deadline);

        return $run->fresh();
    }

    public function runToCompletion(ImageHashRun $run, int $maxChunks = 100_000): ImageHashRun
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

    private function onlyMissingSignature(ProductImage $image): bool
    {
        return is_string($image->perceptual_hash)
            && $image->perceptual_hash !== ''
            && is_array($image->perceptual_hashes)
            && $image->perceptual_hashes !== []
            && is_string($image->dct_hash)
            && $image->dct_hash !== ''
            && is_array($image->embedding_vector)
            && count($image->embedding_vector) === ProductImageEmbeddingService::DIMENSIONS;
    }

    /**
     * Compile the inbox match index now so the first inbox match after a rebuild is fast.
     */
    private function afterRebuild(): void
    {
        try {
            Cache::forget('image-match-index-health');
            $this->matchIndex()->entries();
        } catch (Throwable $e) {
            Log::warning('Image match index warm-up after rebuild failed.', ['error' => $e->getMessage()]);
        }
    }

    private function matchIndex(): ProductImageMatchIndex
    {
        return app(ProductImageMatchIndex::class);
    }
}
