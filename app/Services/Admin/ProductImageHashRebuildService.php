<?php

namespace App\Services\Admin;

use App\Models\ImageHashRun;
use App\Models\ProductImage;
use App\Models\User;
use Throwable;

class ProductImageHashRebuildService
{
    public function __construct(
        private readonly ProductImageHashService $hasher,
    ) {}

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
     * @return array{total:int, hashed:int, missing:int}
     */
    public function coverage(): array
    {
        $total = ProductImage::query()->count();
        $hashed = ProductImage::query()->whereNotNull('perceptual_hash')->count();

        return [
            'total' => $total,
            'hashed' => $hashed,
            'missing' => max(0, $total - $hashed),
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
            $query->whereNull('perceptual_hash');
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

            $chunkSize = (int) ($run->meta['chunk_size'] ?? config('products.image_hash_chunk_size', 25));
            $cursor = (int) $run->image_cursor;

            $query = ProductImage::query()
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($chunkSize);

            if (! $run->force) {
                $query->whereNull('perceptual_hash');
            }

            $images = $query->get();

            if ($images->isEmpty()) {
                $run->update([
                    'status' => 'completed',
                    'phase' => 'done',
                    'message' => sprintf(
                        'Done — hashed %s, failed %s',
                        number_format($run->hashed_ok),
                        number_format($run->failed),
                    ),
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
                $lastId = (int) $image->id;
                $processed++;

                try {
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
