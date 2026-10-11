<?php

namespace App\Services\Admin;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Local GD visual embedding: 16-bin hue histogram + 8×8 luminance grid (80 floats).
 * Used as a multi-view fallback when dHash/DCT miss. No ML APIs / Composer deps.
 */
class ProductImageEmbeddingService
{
    public const HUE_BINS = 16;

    public const GRID = 8;

    public const DIMENSIONS = self::HUE_BINS + (self::GRID * self::GRID);

    /** Cosine similarity required for auto-match (≈90% hash confidence). */
    public const AUTO_SIMILARITY = 0.92;

    public function embedBinary(string $binary): array
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

        try {
            return $this->embedGdImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @return list<float>
     */
    public function embedGdImage(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1) {
            throw new RuntimeException('Image has no pixels.');
        }

        $hue = array_fill(0, self::HUE_BINS, 0.0);
        $grid = array_fill(0, self::GRID * self::GRID, 0.0);
        $gridCount = array_fill(0, self::GRID * self::GRID, 0);

        $sampleStep = max(1, (int) floor(min($width, $height) / 64));

        for ($y = 0; $y < $height; $y += $sampleStep) {
            for ($x = 0; $x < $width; $x += $sampleStep) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $delta = $max - $min;

                if ($delta > 8 && $max > 20) {
                    if ($max === $r) {
                        $h = 60 * fmod((($g - $b) / $delta), 6);
                    } elseif ($max === $g) {
                        $h = 60 * ((($b - $r) / $delta) + 2);
                    } else {
                        $h = 60 * ((($r - $g) / $delta) + 4);
                    }
                    if ($h < 0) {
                        $h += 360;
                    }
                    $bin = (int) min(self::HUE_BINS - 1, floor($h / (360 / self::HUE_BINS)));
                    $hue[$bin] += 1.0;
                }

                $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                $gx = (int) min(self::GRID - 1, floor(($x / $width) * self::GRID));
                $gy = (int) min(self::GRID - 1, floor(($y / $height) * self::GRID));
                $gi = ($gy * self::GRID) + $gx;
                $grid[$gi] += $luma;
                $gridCount[$gi]++;
            }
        }

        $hueSum = array_sum($hue);
        if ($hueSum > 0) {
            foreach ($hue as $i => $v) {
                $hue[$i] = $v / $hueSum;
            }
        }

        for ($i = 0; $i < count($grid); $i++) {
            $grid[$i] = $gridCount[$i] > 0 ? ($grid[$i] / $gridCount[$i]) / 255.0 : 0.0;
        }

        $vector = array_merge($hue, $grid);

        return $this->l2Normalize($vector);
    }

    public function storeForProductImage(ProductImage $image, string $binary): ?array
    {
        try {
            $vector = $this->embedBinary($binary);
        } catch (Throwable $e) {
            Log::debug('Product image embedding failed.', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $image->update(['embedding_vector' => $vector]);

        return $vector;
    }

    /**
     * @return array{product_id:int,name:string,match_percent:float,strategy:string,distance:int}|null
     */
    public function findBestAutoMatchFromBinary(string $binary): ?array
    {
        return $this->findBestAutoMatchForVector($this->embedBinary($binary), 'embedding');
    }

    public function findBestAutoMatchFromGdImage(\GdImage $image, string $strategy = 'embedding'): ?array
    {
        return $this->findBestAutoMatchForVector($this->embedGdImage($image), $strategy);
    }

    /**
     * @param  list<float>  $query
     * @return array{product_id:int,name:string,match_percent:float,strategy:string,distance:int}|null
     */
    public function findBestAutoMatchForVector(array $query, string $strategy = 'embedding'): ?array
    {
        if (count($query) !== self::DIMENSIONS) {
            return null;
        }

        $best = null;
        $bestSim = 0.0;

        foreach (app(ProductImageCatalogIndex::class)->load()['rows'] as $row) {
            $vector = $row['embedding'] ?? null;
            if (! is_array($vector) || count($vector) !== self::DIMENSIONS) {
                continue;
            }

            $sim = $this->cosineSimilarity($query, $vector);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best = [
                    'product_id' => (int) $row['product_id'],
                    'name' => (string) $row['name'],
                    'match_percent' => round($sim * 100, 2),
                    'strategy' => $strategy,
                    'distance' => (int) round((1 - $sim) * ProductImageHashService::HASH_BITS),
                ];
            }
        }

        if ($best === null || $bestSim < (ProductImageHashService::autoMatchPercent() / 100)) {
            return null;
        }

        return $best;
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function l2Normalize(array $vector): array
    {
        $sumSq = 0.0;
        foreach ($vector as $v) {
            $sumSq += $v * $v;
        }

        $norm = sqrt($sumSq);
        if ($norm < 1e-9) {
            return $vector;
        }

        return array_map(fn (float $v): float => $v / $norm, $vector);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += ((float) $a[$i]) * ((float) $b[$i]);
        }

        return max(0.0, min(1.0, $dot));
    }
}
