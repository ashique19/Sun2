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
    private const CHUNK = 25;

    private const ALLOWED_TAGS = '<p><br><br/><ul><ol><li><strong><b><em><i><u><a><h2><h3><h4><span><div>';

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

        $query->chunkById(self::CHUNK, function ($rows) use ($dryRun, $force, &$updated, &$skipped, &$scanned, $output): void {
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

    private function assertLegacyConnection(): void
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Legacy DB connection failed: '.$e->getMessage(), 0, $e);
        }
    }
}
