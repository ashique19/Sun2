<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Console\Command;

class IndexProductImageHashesCommand extends Command
{
    protected $signature = 'products:index-image-hashes
                            {--chunk=100 : Images per chunk}
                            {--force : Re-hash images that already have a hash}
                            {--limit=0 : Stop after N images (0 = all)}';

    protected $description = 'Backfill perceptual hashes for product images (local or CDN)';

    public function handle(ProductImageHashService $hasher): int
    {
        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));

        $query = ProductImage::query()->orderBy('id');

        if (! $force) {
            $query->whereNull('perceptual_hash');
        }

        $total = (clone $query)->count();

        if ($limit > 0) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('Nothing to index.');

            return self::SUCCESS;
        }

        $this->info("Indexing {$total} product image(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $failed = 0;
        $processed = 0;

        $query->chunkById($chunk, function ($images) use ($hasher, $force, $limit, &$ok, &$failed, &$processed, $bar) {
            foreach ($images as $image) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                try {
                    if (! $force && $image->perceptual_hash) {
                        $bar->advance();

                        continue;
                    }

                    $hash = $hasher->storeHash($image, allowRemoteDownload: true);

                    if ($hash) {
                        $ok++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Image #{$image->id}: ".$e->getMessage());
                }

                $bar->advance();
            }

            return $limit === 0 || $processed < $limit;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. hashed={$ok} failed={$failed}");

        return self::SUCCESS;
    }
}
