<?php

namespace App\Services\Admin;

use App\Models\ImageHashRun;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ProductImageHashRebuildService
{
    public function __construct(
        private readonly ProductImageHashService $hasher,
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
            && count($image->embedding_vector) === ProductImageEmbeddingService::DIMENSIONS;
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
                });
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
     * Process one chunk. Returns true when finished.
     */
    public function processChunk(ImageHashRun $run): bool
    {
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
            $chunkDeadline = microtime(true) + 8;

            if (! $run->force) {
                $query = $this->incompleteIndexQuery()->where('id', '>', $cursor);
            } else {
                $query = ProductImage::query()->where('id', '>', $cursor);
            }

            $images = $query->orderBy('id')->limit($chunkSize)->get();

            if ($images->isEmpty()) {
                $stillIncomplete = ! $run->force && $this->incompleteIndexQuery()->exists();

                $run->update([
                    'status' => $stillIncomplete ? 'failed' : 'completed',
                    'phase' => $stillIncomplete ? 'failed' : 'done',
                    'message' => $stillIncomplete
                        ? 'Rebuild stopped before all images were processed'
                        : sprintf(
                            'Done — hashed %s, failed %s',
                            number_format($run->hashed_ok),
                            number_format($run->failed),
                        ),
                    'error' => $stillIncomplete
                        ? 'Some images were not reached during this run. Try again, use “Re-hash all images”, or run php artisan products:index-image-hashes on the server.'
                        : null,
                    'progress_current' => (int) $run->progress_total,
                    'finished_at' => now(),
                ]);

                return true;
            }

            $ok = (int) $run->hashed_ok;
            $failed = (int) $run->failed;
            $processed = (int) $run->progress_current;
            $lastId = $cursor;

            foreach ($images as $image) {
                if (microtime(true) >= $chunkDeadline) {
                    break;
                }

                $lastId = (int) $image->id;
                $processed++;

                try {
                    if (! $run->force && $this->isFullyIndexed($image)) {
                        continue;
                    }

                    $hash = $this->hasher->storeHash($image, allowRemoteDownload: true);

                    if ($hash) {
                        $ok++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable) {
                    $failed++;
                }
            }

            $run->update([
                'image_cursor' => $lastId,
                'progress_current' => min($processed, (int) $run->progress_total),
                'hashed_ok' => $ok,
                'failed' => $failed,
                'message' => sprintf(
                    'Hashing… %s / %s (ok %s, failed %s)',
                    number_format(min($processed, (int) $run->progress_total)),
                    number_format((int) $run->progress_total),
                    number_format($ok),
                    number_format($failed),
                ),
            ]);

            $attemptedAll = min($processed, (int) $run->progress_total) >= (int) $run->progress_total;

            $hasMoreByCursor = $run->force
                ? ProductImage::query()->where('id', '>', $lastId)->exists()
                : $this->incompleteIndexQuery()->where('id', '>', $lastId)->exists();

            if ($attemptedAll || ! $hasMoreByCursor) {
                $stillIncomplete = ! $run->force && $this->incompleteIndexQuery()->exists();

                $run->update([
                    'status' => ($stillIncomplete && ! $attemptedAll) ? 'failed' : 'completed',
                    'phase' => ($stillIncomplete && ! $attemptedAll) ? 'failed' : 'done',
                    'message' => ($stillIncomplete && ! $attemptedAll)
                        ? 'Rebuild stopped before all images were processed'
                        : sprintf(
                            'Done — hashed %s, failed %s',
                            number_format($ok),
                            number_format($failed),
                        ),
                    'error' => ($stillIncomplete && ! $attemptedAll)
                        ? 'Some images were not reached during this run. Try again, use “Re-hash all images”, or run php artisan products:index-image-hashes on the server.'
                        : null,
                    'progress_current' => (int) $run->progress_total,
                    'finished_at' => now(),
                ]);

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
}
