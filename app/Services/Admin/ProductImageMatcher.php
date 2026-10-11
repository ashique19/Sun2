<?php

namespace App\Services\Admin;

use GdImage;

/**
 * Screenshot → catalog product matcher (local, GD only).
 *
 * 1. Propose photo regions inside the screenshot (FB viewer/feed, Messenger cards, site pages).
 * 2. Coarse pass: every proposal vs every indexed signature (8×8 luminance NCC + chroma).
 * 3. Refine the shortlist: jittered query regions (imprecise bounds), paired edge trims
 *    (price bands / caption overlays) and a catalog sub-window search (customer crops).
 */
class ProductImageMatcher
{
    public const SHORTLIST = 40;

    public const WINDOW_CANDIDATES = 10;

    private const COARSE_GRAY_WEIGHT = 0.7;

    private const FINE_GRAY_WEIGHT = 0.7;

    /** Score handicap for full-frame regions of tall screenshots (UI chrome vs UI chrome). */
    private const FULL_FRAME_SCREENSHOT_PENALTY = 0.06;

    /** @var list<array{0:float,1:float,2:float}> dx, dy, scale */
    private const JITTERS = [
        [0.0, 0.0, 1.0],
        [-0.04, 0.0, 1.0], [0.04, 0.0, 1.0], [0.0, -0.04, 1.0], [0.0, 0.04, 1.0],
        [0.0, 0.0, 0.92], [0.0, 0.0, 1.08], [0.0, 0.0, 0.84],
        [-0.04, -0.04, 0.92], [0.04, 0.04, 0.92], [-0.04, 0.04, 0.92], [0.04, -0.04, 0.92],
    ];

    /**
     * The same sub-window applied to query and catalog: drops a price band / caption strip.
     *
     * @var list<array{0:float,1:float,2:float,3:float}>
     */
    private const PAIRED_TRIMS = [
        [0.0, 0.0, 1.0, 0.86], [0.0, 0.14, 1.0, 0.86],
        [0.0, 0.0, 1.0, 0.72], [0.0, 0.28, 1.0, 0.72],
        [0.0, 0.0, 0.86, 1.0], [0.14, 0.0, 0.86, 1.0],
    ];

    public function __construct(
        private ProductImageSignatureService $signatures,
        private ProductImageMatchIndex $index,
    ) {}

    public static function autoScore(): float
    {
        return (float) config('channels.image_match.auto_score', 0.86);
    }

    public static function autoMargin(): float
    {
        return (float) config('channels.image_match.auto_margin', 0.05);
    }

    public static function suggestScore(): float
    {
        return (float) config('channels.image_match.suggest_score', 0.6);
    }

    /**
     * @param  array{left: float, top: float, width: float, height: float}|null  $regionFractions  limit to a staff crop
     * @return array{
     *     candidates: list<array{product_id:int, score:float, source:string, source_id:int, variant:string, region: array{left: float, top: float, width: float, height: float}}>,
     *     region: ?array{left: float, top: float, width: float, height: float},
     *     proposals: int,
     *     timings: array<string, float>
     * }
     */
    public function match(string $binary, ?array $regionFractions = null, int $limit = 5): array
    {
        $timings = [];
        $started = microtime(true);

        $image = $this->signatures->decode($binary);

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            $proposals = $regionFractions !== null
                ? [$this->rectFromFractions($regionFractions, $width, $height)]
                : $this->signatures->proposals($image);
            $timings['decode_propose'] = microtime(true) - $started;

            $mark = microtime(true);
            $isScreenshot = $regionFractions === null && $this->signatures->isPortraitScreenshot($width, $height);
            $queries = [];
            foreach ($proposals as $rect) {
                $description = $this->signatures->describe($image, $rect);
                $isFullFrame = 0.9 * $width * $height <= $rect[2] * $rect[3];
                $queries[] = [
                    'rect' => $rect,
                    'coarse' => $this->signatures->grayVector($description['gray'], 8),
                    'chroma' => $this->signatures->chromaVector($description['color']),
                    'penalty' => $isScreenshot && $isFullFrame ? self::FULL_FRAME_SCREENSHOT_PENALTY : 0.0,
                ];
            }

            $entries = $this->index->entries();
            $timings['load_index'] = microtime(true) - $mark;

            if ($entries === []) {
                return ['candidates' => [], 'region' => null, 'proposals' => count($proposals), 'timings' => $timings];
            }

            $mark = microtime(true);
            $shortlist = $this->coarse($queries, $entries);
            $timings['coarse'] = microtime(true) - $mark;

            $mark = microtime(true);
            $refined = $this->refine($image, $queries, $entries, $shortlist);
            $timings['refine'] = microtime(true) - $mark;
        } finally {
            imagedestroy($image);
        }

        $byProduct = [];
        foreach ($refined as $result) {
            $productId = $entries[$result['entry']]['product_id'];
            if (! isset($byProduct[$productId]) || $result['score'] > $byProduct[$productId]['score']) {
                $byProduct[$productId] = $result;
            }
        }
        uasort($byProduct, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $candidates = [];
        foreach (array_slice($byProduct, 0, $limit, true) as $productId => $result) {
            $entry = $entries[$result['entry']];
            $candidates[] = [
                'product_id' => (int) $productId,
                'score' => round($result['score'], 4),
                'source' => $entry['source'],
                'source_id' => $entry['source_id'],
                'variant' => $entry['variant'],
                'region' => $this->fractionsFromRect($result['rect'], $width, $height),
            ];
        }

        $timings['total'] = microtime(true) - $started;

        return [
            'candidates' => $candidates,
            'region' => $candidates[0]['region'] ?? null,
            'proposals' => count($proposals),
            'timings' => $timings,
        ];
    }

    /**
     * Learnable signature for a staff-confirmed photo region (stored as match memory).
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $regionFractions
     * @return list<array{variant: string, gray: string, color: string}>
     */
    public function learnedSignatures(string $binary, ?array $regionFractions): array
    {
        $image = $this->signatures->decode($binary);

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $full = [0, 0, $width, $height];
            $rects = [$full];

            if ($regionFractions !== null) {
                $region = $this->rectFromFractions($regionFractions, $width, $height);
                if (0.9 * $width * $height > $region[2] * $region[3]) {
                    $rects = [$region];
                }
            } elseif ($this->signatures->isPortraitScreenshot($width, $height)) {
                $top = $this->signatures->proposals($image)[0] ?? $full;
                if (0.75 * $width * $height > $top[2] * $top[3]) {
                    $rects = [$top];
                }
            }

            return array_map(fn (array $rect): array => [
                'variant' => $rect === $full ? 'full' : 'region',
                ...$this->signatures->describe($image, $rect),
            ], $rects);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Whether the top candidate is confident enough to attach without staff review.
     *
     * @param  list<array{product_id:int, score:float}>  $candidates
     */
    public static function isConfident(array $candidates): bool
    {
        $top = $candidates[0]['score'] ?? null;
        if ($top === null || $top < self::autoScore()) {
            return false;
        }

        $runnerUp = $candidates[1]['score'] ?? 0.0;

        return ($top - $runnerUp) >= self::autoMargin();
    }

    /**
     * @param  list<array{rect: array{0:int,1:int,2:int,3:int}, coarse: list<float>, chroma: list<float>, penalty: float}>  $queries
     * @param  list<array{coarse: list<float>, chroma: list<float>}>  $entries
     * @return array<int, array{score: float, query: int}> entry index => best coarse score
     */
    private function coarse(array $queries, array $entries): array
    {
        $grayWeight = self::COARSE_GRAY_WEIGHT;
        $chromaWeight = 1 - $grayWeight;
        $chromaDivisor = 32 * ProductImageSignatureService::CHROMA_SCALE;
        $scores = [];

        foreach ($entries as $entryIndex => $entry) {
            $entryCoarse = $entry['coarse'];
            $entryChroma = $entry['chroma'];
            $best = -2.0;
            $bestQuery = 0;

            foreach ($queries as $queryIndex => $query) {
                $gray = 0.0;
                foreach ($query['coarse'] as $i => $value) {
                    $gray += $value * $entryCoarse[$i];
                }
                $distance = 0.0;
                foreach ($query['chroma'] as $i => $value) {
                    $distance += abs($value - $entryChroma[$i]);
                }
                $chroma = $distance >= $chromaDivisor ? 0.0 : 1.0 - ($distance / $chromaDivisor);
                $score = ($grayWeight * $gray) + ($chromaWeight * $chroma) - $query['penalty'];
                if ($score > $best) {
                    $best = $score;
                    $bestQuery = $queryIndex;
                }
            }

            $scores[$entryIndex] = ['score' => $best, 'query' => $bestQuery];
        }

        uasort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scores, 0, self::SHORTLIST, true);
    }

    /**
     * @param  list<array{rect: array{0:int,1:int,2:int,3:int}, coarse: list<float>, chroma: list<float>, penalty: float}>  $queries
     * @param  list<array{gray: string, rgb: string, chroma: list<float>}>  $entries
     * @param  array<int, array{score: float, query: int}>  $shortlist
     * @return list<array{entry:int, score:float, rect: array{0:int,1:int,2:int,3:int}}>
     */
    private function refine(GdImage $image, array $queries, array $entries, array $shortlist): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Jitter each query region that won at least one shortlisted entry (bounded).
        $queryVotes = [];
        foreach ($shortlist as $hit) {
            $queryVotes[$hit['query']] = max($queryVotes[$hit['query']] ?? -2.0, $hit['score']);
        }
        arsort($queryVotes);
        $queryVotes = array_slice($queryVotes, 0, 4, true);

        $jittered = [];
        foreach (array_keys($queryVotes) as $queryIndex) {
            $base = $queries[$queryIndex]['rect'];
            $penalty = $queries[$queryIndex]['penalty'];
            foreach (self::JITTERS as [$dx, $dy, $scale]) {
                $rect = $this->signatures->jitter($base, $dx, $dy, $scale, $width, $height);
                $jittered[] = ['rect' => $rect, 'penalty' => $penalty, ...$this->queryVectors($image, $rect)];
            }
        }

        $results = [];
        foreach (array_keys($shortlist) as $entryIndex) {
            $entry = $entries[$entryIndex];
            $entryVectors = ['gray' => $this->signatures->grayVector($entry['gray'], 16), 'chroma' => $entry['chroma']];
            $scored = [];

            foreach ($jittered as $jitterIndex => $query) {
                $scored[$jitterIndex] = $this->fineScore($query, $entryVectors) - $query['penalty'];
            }
            arsort($scored);
            $bestJitter = array_key_first($scored);

            $results[$entryIndex] = [
                'entry' => $entryIndex,
                'score' => $scored[$bestJitter],
                'rect' => $jittered[$bestJitter]['rect'],
                'penalty' => $jittered[$bestJitter]['penalty'],
                'top_jitters' => array_slice(array_keys($scored), 0, 2),
            ];
        }

        uasort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $leader = (float) reset($results)['score'];

        foreach (array_slice(array_keys($results), 0, self::WINDOW_CANDIDATES) as $entryIndex) {
            $entry = $entries[$entryIndex];
            $result = &$results[$entryIndex];

            // Overlays near one edge (price band, caption): trim query and catalog identically.
            $baseRect = $result['rect'];
            foreach (self::PAIRED_TRIMS as $trim) {
                $queryRect = $this->subRect($baseRect, $trim, $width, $height);
                $score = $this->fineScore(
                    $this->queryVectors($image, $queryRect),
                    $this->signatures->windowVectors($entry['gray'], $entry['rgb'], $trim),
                ) - $result['penalty'];
                if ($score > $result['score']) {
                    $result['score'] = $score;
                    $result['rect'] = $queryRect;
                }
            }

            $leader = max($leader, $result['score']);

            // Customer crops: the query shows only part of the catalog photo. Skip when a
            // strong leader already exists and this candidate is far behind it.
            $farBehindStrongLeader = $leader >= self::autoScore() + 0.06 && $result['score'] < $leader - 0.12;
            if ($result['score'] < self::autoScore() + self::autoMargin() && ! $farBehindStrongLeader) {
                $windowScore = $this->bestCatalogWindow($entry, array_map(
                    fn (int $jitterIndex): array => $jittered[$jitterIndex],
                    $result['top_jitters'],
                ));
                if ($windowScore['score'] > $result['score']) {
                    $result['score'] = $windowScore['score'];
                    $result['rect'] = $windowScore['rect'];
                }
            }
            unset($result);
        }

        return array_values(array_map(fn (array $result): array => [
            'entry' => $result['entry'],
            'score' => $result['score'],
            'rect' => $result['rect'],
        ], $results));
    }

    /**
     * Coarse grid over catalog sub-windows, then a short hill-climb on position and size.
     *
     * @param  array{gray: string, rgb: string}  $entry
     * @param  list<array{rect: array{0:int,1:int,2:int,3:int}, penalty: float, gray: list<float>, chroma: list<float>}>  $queries
     * @return array{score: float, rect: array{0:int,1:int,2:int,3:int}}
     */
    private function bestCatalogWindow(array $entry, array $queries): array
    {
        $evaluate = function (array $window) use ($entry, $queries): array {
            $vectors = $this->signatures->windowVectors($entry['gray'], $entry['rgb'], $window);
            $best = ['score' => -2.0, 'rect' => $queries[0]['rect']];
            foreach ($queries as $query) {
                $score = $this->fineScore($query, $vectors) - $query['penalty'];
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'rect' => $query['rect']];
                }
            }

            return $best;
        };

        $bestWindow = null;
        $best = ['score' => -2.0, 'rect' => $queries[0]['rect']];
        foreach ([[0.84, 0.84], [0.72, 0.72], [0.84, 0.72], [0.72, 0.84]] as [$w, $h]) {
            foreach ([0.0, 0.5, 1.0] as $fy) {
                foreach ([0.0, 0.5, 1.0] as $fx) {
                    $window = [(1 - $w) * $fx, (1 - $h) * $fy, $w, $h];
                    $result = $evaluate($window);
                    if ($result['score'] > $best['score']) {
                        $best = $result;
                        $bestWindow = $window;
                    }
                }
            }
        }

        $step = 0.04;
        for ($round = 0; $round < 3 && $bestWindow !== null; $round++) {
            $improved = false;
            foreach ([[$step, 0, 0, 0], [-$step, 0, 0, 0], [0, $step, 0, 0], [0, -$step, 0, 0], [0, 0, $step, 0], [0, 0, -$step, 0], [0, 0, 0, $step], [0, 0, 0, -$step]] as [$dx, $dy, $dw, $dh]) {
                $w = min(1.0, max(0.5, $bestWindow[2] + $dw));
                $h = min(1.0, max(0.5, $bestWindow[3] + $dh));
                $window = [
                    min(1 - $w, max(0.0, $bestWindow[0] + $dx)),
                    min(1 - $h, max(0.0, $bestWindow[1] + $dy)),
                    $w,
                    $h,
                ];
                $result = $evaluate($window);
                if ($result['score'] > $best['score'] + 0.001) {
                    $best = $result;
                    $bestWindow = $window;
                    $improved = true;
                }
            }
            if (! $improved) {
                $step /= 2;
            }
        }

        return $best;
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{gray: list<float>, chroma: list<float>}
     */
    private function queryVectors(GdImage $image, array $rect): array
    {
        return $this->signatures->describeFine($image, $rect);
    }

    /**
     * @param  array{gray: list<float>, chroma: list<float>}  $query
     * @param  array{gray: list<float>, chroma: list<float>}  $entry
     */
    private function fineScore(array $query, array $entry): float
    {
        return (self::FINE_GRAY_WEIGHT * $this->signatures->dot($query['gray'], $entry['gray']))
            + ((1 - self::FINE_GRAY_WEIGHT) * $this->signatures->chromaSimilarity($query['chroma'], $entry['chroma']));
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @param  array{0:float,1:float,2:float,3:float}  $fractions
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function subRect(array $rect, array $fractions, int $width, int $height): array
    {
        return $this->signatures->clampRect([
            (int) round($rect[0] + ($fractions[0] * $rect[2])),
            (int) round($rect[1] + ($fractions[1] * $rect[3])),
            (int) round($fractions[2] * $rect[2]),
            (int) round($fractions[3] * $rect[3]),
        ], $width, $height);
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function fractionsFromRect(array $rect, int $width, int $height): array
    {
        return [
            'left' => round($rect[0] / $width, 4),
            'top' => round($rect[1] / $height, 4),
            'width' => round($rect[2] / $width, 4),
            'height' => round($rect[3] / $height, 4),
        ];
    }

    /**
     * @param  array{left: float, top: float, width: float, height: float}  $fractions
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function rectFromFractions(array $fractions, int $width, int $height): array
    {
        return $this->signatures->clampRect([
            (int) floor((float) $fractions['left'] * $width),
            (int) floor((float) $fractions['top'] * $height),
            (int) ceil((float) $fractions['width'] * $width),
            (int) ceil((float) $fractions['height'] * $height),
        ], $width, $height);
    }
}
