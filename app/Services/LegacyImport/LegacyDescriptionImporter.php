<?php

namespace App\Services\LegacyImport;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 legacy import: backfill products.description / description_bn only.
 * Main import:legacy intentionally skipped these HTML blobs.
 */
class LegacyDescriptionImporter
{
    public const BATCH_SIZE = 100;

    private const ALLOWED_TAGS = '<p><br><br/><ul><ol><li><strong><b><em><i><u><a><h2><h3><h4><span><div>';

    /**
     * Legacy products that have English and/or Bangla detail HTML to copy.
     */
    public function eligibleLegacyCount(): int
    {
        $this->assertLegacyConnection();

        return (int) DB::connection('legacy')->table('products')
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->whereNotNull('product_detail')
                        ->where('product_detail', '!=', '');
                })->orWhere(function ($inner): void {
                    $inner->whereNotNull('product_detail_bn')
                        ->where('product_detail_bn', '!=', '');
                });
            })
            ->count();
    }

    /**
     * Copy the next batch of legacy product descriptions into sun2 products.
     *
     * @return array{
     *     scanned: int,
     *     updated: int,
     *     skipped: int,
     *     next_after_id: int,
     *     done: bool,
     *     sample_names: list<string>
     * }
     */
    public function importNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE, bool $force = false): array
    {
        $this->assertLegacyConnection();

        $limit = max(1, min(self::BATCH_SIZE, $limit));

        $rows = DB::connection('legacy')->table('products')
            ->select(['id', 'product_detail', 'product_detail_bn'])
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->whereNotNull('product_detail')
                        ->where('product_detail', '!=', '');
                })->orWhere(function ($inner): void {
                    $inner->whereNotNull('product_detail_bn')
                        ->where('product_detail_bn', '!=', '');
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'scanned' => 0,
                'updated' => 0,
                'skipped' => 0,
                'next_after_id' => $afterId,
                'done' => true,
                'sample_names' => [],
            ];
        }

        $ids = $rows->pluck('id')->all();
        $targets = DB::connection()->table('products')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'description', 'description_bn'])
            ->keyBy('id');

        $updated = 0;
        $skipped = 0;
        $samples = [];

        foreach ($rows as $row) {
            $target = $targets->get($row->id);

            if ($target === null) {
                $skipped++;

                continue;
            }

            $en = $this->sanitizeHtml((string) ($row->product_detail ?? ''));
            $bn = $this->sanitizeHtml((string) ($row->product_detail_bn ?? ''));

            if ($en === '' && $bn === '') {
                $skipped++;

                continue;
            }

            $hasEn = filled($target->description);
            $hasBn = filled($target->description_bn);

            if (! $force && $hasEn && $hasBn) {
                $skipped++;

                continue;
            }

            $payload = ['updated_at' => now()];

            if ($force || ! $hasEn) {
                $payload['description'] = $en !== '' ? $en : null;
            }

            if ($force || ! $hasBn) {
                $payload['description_bn'] = $bn !== '' ? $bn : null;
            }

            if (count($payload) === 1) {
                $skipped++;

                continue;
            }

            DB::connection()->table('products')->where('id', $row->id)->update($payload);
            $updated++;

            if (count($samples) < 8) {
                $samples[] = trim((string) ($target->name ?: '#'.$row->id));
            }
        }

        $lastId = (int) $rows->last()->id;

        return [
            'scanned' => $rows->count(),
            'updated' => $updated,
            'skipped' => $skipped,
            'next_after_id' => $lastId,
            'done' => $rows->count() < $limit,
            'sample_names' => $samples,
        ];
    }

    /**
     * @return array{updated: int, skipped: int, scanned: int}
     */
    public function run(Command $output, bool $dryRun = false, ?int $fromId = null, bool $force = false): array
    {
        $this->assertLegacyConnection();

        $query = DB::connection('legacy')->table('products')
            ->select(['id', 'product_detail', 'product_detail_bn'])
            ->orderBy('id');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        $updated = 0;
        $skipped = 0;
        $scanned = 0;

        $query->chunkById(self::BATCH_SIZE, function ($rows) use ($dryRun, $force, &$updated, &$skipped, &$scanned, $output): void {
            $ids = $rows->pluck('id')->all();
            $targets = DB::connection()->table('products')
                ->whereIn('id', $ids)
                ->get(['id', 'description', 'description_bn'])
                ->keyBy('id');

            foreach ($rows as $row) {
                $scanned++;
                $target = $targets->get($row->id);

                if ($target === null) {
                    $skipped++;

                    continue;
                }

                $en = $this->sanitizeHtml((string) ($row->product_detail ?? ''));
                $bn = $this->sanitizeHtml((string) ($row->product_detail_bn ?? ''));

                if ($en === '' && $bn === '') {
                    $skipped++;

                    continue;
                }

                $hasEn = filled($target->description);
                $hasBn = filled($target->description_bn);

                if (! $force && $hasEn && $hasBn) {
                    $skipped++;

                    continue;
                }

                $payload = ['updated_at' => now()];

                if ($force || ! $hasEn) {
                    $payload['description'] = $en !== '' ? $en : null;
                }

                if ($force || ! $hasBn) {
                    $payload['description_bn'] = $bn !== '' ? $bn : null;
                }

                if (count($payload) === 1) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    DB::connection()->table('products')->where('id', $row->id)->update($payload);
                }

                $updated++;
            }

            $output->line("  … scanned {$scanned}, updated {$updated}, skipped {$skipped}");
        }, 'id');

        return compact('updated', 'skipped', 'scanned');
    }

    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        // Drop scripts/styles aggressively before allowlist strip.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = strip_tags($html, self::ALLOWED_TAGS);
        // Remove on* handlers / javascript: URLs from remaining tags.
        $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\shref\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', ' href="#"', $html) ?? $html;

        return trim($html);
    }

    public function assertLegacyConnection(): void
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Legacy DB connection failed: '.$e->getMessage(), 0, $e);
        }
    }
}
