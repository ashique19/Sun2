<?php

namespace App\Services\Admin;

use GdImage;
use RuntimeException;

/**
 * Compact visual signatures for screenshot → catalog matching (GD only, no external APIs).
 *
 * A signature is a 32×32 grayscale thumbnail plus a 16×16 RGB thumbnail of one image
 * region. Catalog images store a "full" and (when letterboxed) a "trim" variant; inbound
 * screenshots are described per region proposal (detected photo panels, cards, crops).
 */
class ProductImageSignatureService
{
    /** Bump when the signature layout or proposal logic changes (forces reindex / rematch). */
    public const VERSION = 1;

    public const GRAY_SIZE = 32;

    public const COLOR_SIZE = 16;

    public const GRAY_BYTES = self::GRAY_SIZE * self::GRAY_SIZE;

    public const COLOR_BYTES = self::COLOR_SIZE * self::COLOR_SIZE * 3;

    /** Work resolution for decoding; signatures are tiny so larger inputs only cost decode time. */
    public const WORK_MAX_EDGE = 640;

    /** Resolution used for row/column statistics when proposing screenshot regions. */
    public const PROPOSAL_MAX_WIDTH = 180;

    public const MAX_PROPOSALS = 10;

    /** Mean Cr/Cb difference (0–255 scale) at which chroma similarity reaches zero. */
    public const CHROMA_SCALE = 40.0;

    private const VARIANT_CODES = ['full' => 1, 'trim' => 2, 'region' => 3];

    /**
     * Decode and downscale to the work resolution (area-averaged).
     */
    public function decode(string $binary): GdImage
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new RuntimeException('Unsupported or corrupt image data.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxEdge = max($width, $height);

        if ($maxEdge <= self::WORK_MAX_EDGE) {
            return $image;
        }

        $scale = self::WORK_MAX_EDGE / $maxEdge;
        $scaled = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $width, $height);
        imagedestroy($image);

        return $scaled;
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $rect  x, y, width, height
     * @return array{gray: string, color: string}
     */
    public function describe(GdImage $image, array $rect): array
    {
        [$x, $y, $width, $height] = $rect;

        $gray = imagecreatetruecolor(self::GRAY_SIZE, self::GRAY_SIZE);
        imagecopyresampled($gray, $image, 0, 0, $x, $y, self::GRAY_SIZE, self::GRAY_SIZE, $width, $height);

        $grayBytes = '';
        for ($py = 0; $py < self::GRAY_SIZE; $py++) {
            for ($px = 0; $px < self::GRAY_SIZE; $px++) {
                $rgb = imagecolorat($gray, $px, $py);
                $grayBytes .= chr((int) round(
                    ((($rgb >> 16) & 0xFF) * 0.299) + ((($rgb >> 8) & 0xFF) * 0.587) + (($rgb & 0xFF) * 0.114)
                ));
            }
        }
        imagedestroy($gray);

        $color = imagecreatetruecolor(self::COLOR_SIZE, self::COLOR_SIZE);
        imagecopyresampled($color, $image, 0, 0, $x, $y, self::COLOR_SIZE, self::COLOR_SIZE, $width, $height);

        $colorBytes = '';
        for ($py = 0; $py < self::COLOR_SIZE; $py++) {
            for ($px = 0; $px < self::COLOR_SIZE; $px++) {
                $rgb = imagecolorat($color, $px, $py);
                $colorBytes .= chr(($rgb >> 16) & 0xFF).chr(($rgb >> 8) & 0xFF).chr($rgb & 0xFF);
            }
        }
        imagedestroy($color);

        return ['gray' => $grayBytes, 'color' => $colorBytes];
    }

    /**
     * Fast refine-stage descriptor: one 16×16 resample → normalized gray vector + 4×4 chroma.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{gray: list<float>, chroma: list<float>}
     */
    public function describeFine(GdImage $image, array $rect): array
    {
        [$x, $y, $width, $height] = $rect;

        $small = imagecreatetruecolor(16, 16);
        imagecopyresampled($small, $image, 0, 0, $x, $y, 16, 16, $width, $height);

        $gray = [];
        $cr = array_fill(0, 16, 0.0);
        $cb = array_fill(0, 16, 0.0);
        for ($py = 0; $py < 16; $py++) {
            $cellRow = ($py >> 2) * 4;
            for ($px = 0; $px < 16; $px++) {
                $rgb = imagecolorat($small, $px, $py);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                $gray[] = $luma;
                $cell = $cellRow + ($px >> 2);
                $cr[$cell] += $r - $luma;
                $cb[$cell] += $b - $luma;
            }
        }
        imagedestroy($small);

        $chroma = [];
        for ($cell = 0; $cell < 16; $cell++) {
            $chroma[] = $cr[$cell] / 16;
            $chroma[] = $cb[$cell] / 16;
        }

        return ['gray' => $this->normalize($gray), 'chroma' => $chroma];
    }

    /**
     * Catalog variants: the full frame plus a letterbox-trimmed frame when padding is significant.
     *
     * @return list<array{variant: string, gray: string, color: string}>
     */
    public function catalogSignatures(string $binary): array
    {
        $image = $this->decode($binary);

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $variants = [['variant' => 'full', ...$this->describe($image, [0, 0, $width, $height])]];

            $trim = $this->uniformBorderTrim($image);
            if ($trim !== null && ($trim[2] * $trim[3]) < 0.9 * $width * $height) {
                $variants[] = ['variant' => 'trim', ...$this->describe($image, $trim)];
            }

            // Some catalog photos are themselves phone screenshots: index the photo panel too.
            if ($this->isPortraitScreenshot($width, $height)) {
                $panel = $this->proposals($image)[0] ?? null;
                if ($panel !== null && ($panel[2] * $panel[3]) < 0.75 * $width * $height) {
                    $variants[] = ['variant' => 'region', ...$this->describe($image, $panel)];
                }
            }

            return $variants;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @param  list<array{variant: string, gray: string, color: string}>  $signatures
     */
    public function pack(array $signatures): string
    {
        $binary = chr(self::VERSION).chr(count($signatures));

        foreach ($signatures as $signature) {
            $binary .= chr(self::VARIANT_CODES[$signature['variant']] ?? 3).$signature['gray'].$signature['color'];
        }

        return base64_encode($binary);
    }

    /**
     * @return list<array{variant: string, gray: string, color: string}>
     */
    public function unpack(?string $packed): array
    {
        if (! is_string($packed) || $packed === '') {
            return [];
        }

        $binary = base64_decode($packed, true);
        if ($binary === false || strlen($binary) < 2 || ord($binary[0]) !== self::VERSION) {
            return [];
        }

        $count = ord($binary[1]);
        $chunk = 1 + self::GRAY_BYTES + self::COLOR_BYTES;
        $codes = array_flip(self::VARIANT_CODES);
        $signatures = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = 2 + ($i * $chunk);
            if (strlen($binary) < $offset + $chunk) {
                break;
            }

            $signatures[] = [
                'variant' => $codes[ord($binary[$offset])] ?? 'region',
                'gray' => substr($binary, $offset + 1, self::GRAY_BYTES),
                'color' => substr($binary, $offset + 1 + self::GRAY_BYTES, self::COLOR_BYTES),
            ];
        }

        return $signatures;
    }

    public function isCurrent(?string $packed): bool
    {
        return $this->unpack($packed) !== [];
    }

    /**
     * Zero-mean, unit-length grid of block means (8×8 → 64 dims, 16×16 → 256 dims).
     *
     * @return list<float>
     */
    public function grayVector(string $gray, int $grid): array
    {
        $block = intdiv(self::GRAY_SIZE, $grid);
        $values = [];

        for ($gy = 0; $gy < $grid; $gy++) {
            for ($gx = 0; $gx < $grid; $gx++) {
                $sum = 0;
                for ($y = $gy * $block, $yEnd = $y + $block; $y < $yEnd; $y++) {
                    $row = $y * self::GRAY_SIZE;
                    for ($x = $gx * $block, $xEnd = $x + $block; $x < $xEnd; $x++) {
                        $sum += ord($gray[$row + $x]);
                    }
                }
                $values[] = $sum / ($block * $block);
            }
        }

        return $this->normalize($values);
    }

    /**
     * Absolute chroma (Cr, Cb) of a 4×4 grid. Kept un-normalized so neutral silver items stay
     * near zero and color variants of one design (gold vs silver, pink vs blue) stay far apart.
     *
     * @return list<float>
     */
    public function chromaVector(string $color): array
    {
        return $this->chromaWindow($color, [0.0, 0.0, 1.0, 1.0]);
    }

    /**
     * Sub-window of a stored signature as a 16×16 gray vector and a 4×4 chroma vector.
     *
     * @param  array{0:float,1:float,2:float,3:float}  $window  fractions: left, top, width, height
     * @return array{gray: list<float>, chroma: list<float>}
     */
    public function windowVectors(string $gray, string $color, array $window): array
    {
        [$left, $top, $width, $height] = $window;

        $grayValues = [];
        $cellW = self::GRAY_SIZE * $width / 16;
        $cellH = self::GRAY_SIZE * $height / 16;
        $originX = $left * self::GRAY_SIZE;
        $originY = $top * self::GRAY_SIZE;
        $max = self::GRAY_SIZE - 1;
        for ($gy = 0; $gy < 16; $gy++) {
            $y0 = min($max, (int) ($originY + ($gy * $cellH) + ($cellH * 0.25)));
            $y1 = min($max, (int) ($originY + ($gy * $cellH) + ($cellH * 0.75)));
            for ($gx = 0; $gx < 16; $gx++) {
                $x0 = min($max, (int) ($originX + ($gx * $cellW) + ($cellW * 0.25)));
                $x1 = min($max, (int) ($originX + ($gx * $cellW) + ($cellW * 0.75)));
                $grayValues[] = (ord($gray[($y0 * self::GRAY_SIZE) + $x0]) + ord($gray[($y0 * self::GRAY_SIZE) + $x1])
                    + ord($gray[($y1 * self::GRAY_SIZE) + $x0]) + ord($gray[($y1 * self::GRAY_SIZE) + $x1])) / 4;
            }
        }

        return ['gray' => $this->normalize($grayValues), 'chroma' => $this->chromaWindow($color, $window)];
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }

    /**
     * 1.0 for identical chroma, falling linearly with mean absolute Cr/Cb difference.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function chromaSimilarity(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += abs($value - $b[$i]);
        }

        return max(0.0, 1.0 - ($sum / (count($a) * self::CHROMA_SCALE)));
    }

    /**
     * @param  array{0:float,1:float,2:float,3:float}  $window
     * @return list<float>
     */
    private function chromaWindow(string $color, array $window): array
    {
        [$left, $top, $width, $height] = $window;
        $cellW = self::COLOR_SIZE * $width / 4;
        $cellH = self::COLOR_SIZE * $height / 4;
        $originX = $left * self::COLOR_SIZE;
        $originY = $top * self::COLOR_SIZE;
        $max = self::COLOR_SIZE - 1;
        $values = [];

        for ($gy = 0; $gy < 4; $gy++) {
            $yStart = min($max, (int) ($originY + ($gy * $cellH)));
            $yEnd = min($max, max($yStart, (int) ceil($originY + (($gy + 1) * $cellH)) - 1));
            for ($gx = 0; $gx < 4; $gx++) {
                $xStart = min($max, (int) ($originX + ($gx * $cellW)));
                $xEnd = min($max, max($xStart, (int) ceil($originX + (($gx + 1) * $cellW)) - 1));
                $cr = 0.0;
                $cb = 0.0;
                $count = 0;
                for ($y = $yStart; $y <= $yEnd; $y++) {
                    for ($x = $xStart; $x <= $xEnd; $x++) {
                        $offset = (($y * self::COLOR_SIZE) + $x) * 3;
                        $r = ord($color[$offset]);
                        $g = ord($color[$offset + 1]);
                        $b = ord($color[$offset + 2]);
                        $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                        $cr += $r - $luma;
                        $cb += $b - $luma;
                        $count++;
                    }
                }
                $values[] = $cr / $count;
                $values[] = $cb / $count;
            }
        }

        return $values;
    }

    /**
     * Candidate product-photo rectangles inside a screenshot (work-image pixel coords).
     * Always includes the full frame. Ordered roughly by likelihood.
     *
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    public function proposals(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(1.0, self::PROPOSAL_MAX_WIDTH / $width);
        $sw = max(8, (int) round($width * $scale));
        $sh = max(8, (int) round($height * $scale));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $width, $height);

        $gray = [];
        for ($y = 0; $y < $sh; $y++) {
            $row = [];
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $row[] = ((($rgb >> 16) & 0xFF) * 0.299) + ((($rgb >> 8) & 0xFF) * 0.587) + (($rgb & 0xFF) * 0.114);
            }
            $gray[] = $row;
        }
        imagedestroy($small);

        $rowStd = [];
        $rowEdge = [0.0];
        for ($y = 0; $y < $sh; $y++) {
            $rowStd[$y] = $this->stdDev($gray[$y]);
            if ($y > 0) {
                $changed = 0;
                for ($x = 0; $x < $sw; $x++) {
                    if (abs($gray[$y][$x] - $gray[$y - 1][$x]) > 22) {
                        $changed++;
                    }
                }
                $rowEdge[$y] = $changed / $sw;
            }
        }

        $minHeight = max(6, (int) round($sh * 0.1));
        $bands = [];

        // Long runs of textured rows (photos), bridging short flat gaps inside a photo.
        $isTextured = static fn (int $y): bool => $rowStd[$y] >= 9.0;
        $gap = max(2, (int) round($sh * 0.015));
        $start = null;
        $lastTextured = null;
        for ($y = 0; $y <= $sh; $y++) {
            if ($y < $sh && $isTextured($y)) {
                $start ??= $y;
                $lastTextured = $y;

                continue;
            }
            if ($start !== null && ($y === $sh || $y - $lastTextured > $gap)) {
                if ($lastTextured - $start + 1 >= $minHeight) {
                    $bands[] = [$start, $lastTextured];
                }
                $start = null;
            }
        }

        // Strong full-width edges (photo boundaries against UI backgrounds).
        $edges = [0];
        for ($y = 1; $y < $sh; $y++) {
            if ($rowEdge[$y] >= 0.45
                && $rowEdge[$y] >= ($rowEdge[$y - 1] ?? 0)
                && $rowEdge[$y] >= ($rowEdge[$y + 1] ?? 0)
                && $y - end($edges) > 2) {
                $edges[] = $y;
            }
        }
        $edges[] = $sh;
        $edgeCount = count($edges);
        for ($i = 0; $i < $edgeCount; $i++) {
            for ($j = $i + 1; $j < $edgeCount; $j++) {
                $top = $edges[$i];
                $bottom = $edges[$j] - 1;
                if ($bottom - $top + 1 < $minHeight || ($top === 0 && $edges[$j] === $sh)) {
                    continue;
                }
                $textured = 0;
                for ($y = $top; $y <= $bottom; $y++) {
                    $textured += $isTextured($y) ? 1 : 0;
                }
                if ($textured / ($bottom - $top + 1) >= 0.35) {
                    $bands[] = [$top, $bottom];
                }
            }
        }

        $rects = [];
        foreach ($bands as [$top, $bottom]) {
            $rect = $this->bandRect($gray, $sw, $top, $bottom);
            if ($rect !== null) {
                $rects[] = $rect;
                $inner = $this->trimFlatEdges($gray, $rect);
                if ($inner !== null) {
                    $rects[] = $inner;
                }
            }
        }

        // Prefer large, mostly textured, roughly photo-shaped regions.
        $scored = [];
        foreach ($rects as $rect) {
            [$x, $y, $w, $h] = $rect;
            $area = ($w * $h) / ($sw * $sh);
            $aspect = $w / max(1, $h);
            if ($area < 0.04 || $aspect > 3.2 || $aspect < 0.3) {
                continue;
            }
            $textured = 0;
            for ($yy = $y; $yy < $y + $h; $yy++) {
                $textured += $isTextured($yy) ? 1 : 0;
            }
            $scored[] = ['rect' => $rect, 'score' => sqrt($area) * ($textured / $h)];
        }
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $selected = [];
        foreach ($scored as $candidate) {
            foreach ($selected as $existing) {
                if ($this->iou($existing, $candidate['rect']) > 0.85) {
                    continue 2;
                }
            }
            $selected[] = $candidate['rect'];
            if (count($selected) >= self::MAX_PROPOSALS - 1) {
                break;
            }
        }

        $inverse = $width / $sw;
        $inverseH = $height / $sh;
        $proposals = array_map(fn (array $rect): array => $this->clampRect([
            (int) floor($rect[0] * $inverse),
            (int) floor($rect[1] * $inverseH),
            (int) ceil($rect[2] * $inverse),
            (int) ceil($rect[3] * $inverseH),
        ], $width, $height), $selected);

        $proposals[] = [0, 0, $width, $height];

        return $proposals;
    }

    /**
     * Tall phone screenshots: the product photo is rarely the whole frame.
     */
    public function isPortraitScreenshot(int $width, int $height): bool
    {
        return $height >= $width * 1.5;
    }

    /**
     * Shift / scale a rectangle by fractions of its own size (clamped to the image).
     *
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{0:int,1:int,2:int,3:int}
     */
    public function jitter(array $rect, float $dx, float $dy, float $scale, int $width, int $height): array
    {
        [$x, $y, $w, $h] = $rect;
        $nw = $w * $scale;
        $nh = $h * $scale;
        $cx = $x + ($w / 2) + ($dx * $w);
        $cy = $y + ($h / 2) + ($dy * $h);

        return $this->clampRect([
            (int) round($cx - ($nw / 2)),
            (int) round($cy - ($nh / 2)),
            (int) round($nw),
            (int) round($nh),
        ], $width, $height);
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{0:int,1:int,2:int,3:int}
     */
    public function clampRect(array $rect, int $width, int $height): array
    {
        [$x, $y, $w, $h] = $rect;
        $x = max(0, min($width - 1, $x));
        $y = max(0, min($height - 1, $y));
        $w = max(1, min($width - $x, $w));
        $h = max(1, min($height - $y, $h));

        return [$x, $y, $w, $h];
    }

    /**
     * @param  list<list<float>>  $gray
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function bandRect(array $gray, int $sw, int $top, int $bottom): ?array
    {
        $height = $bottom - $top + 1;
        $left = null;
        $right = null;

        for ($x = 0; $x < $sw; $x++) {
            $column = [];
            for ($y = $top; $y <= $bottom; $y++) {
                $column[] = $gray[$y][$x];
            }
            if ($this->stdDev($column) >= 8.0) {
                $left ??= $x;
                $right = $x;
            }
        }

        if ($left === null || $right - $left + 1 < max(6, (int) round($sw * 0.2))) {
            return null;
        }

        return [$left, $top, $right - $left + 1, $height];
    }

    /**
     * Trim near-uniform rows/columns at the rectangle edges (padding inside a photo panel).
     *
     * @param  list<list<float>>  $gray
     * @param  array{0:int,1:int,2:int,3:int}  $rect
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function trimFlatEdges(array $gray, array $rect): ?array
    {
        [$x, $y, $w, $h] = $rect;
        $rowFlat = function (int $row) use ($gray, $x, $w): bool {
            return $this->stdDev(array_slice($gray[$row], $x, $w)) < 6.0;
        };
        $colFlat = function (int $col, int $top, int $bottom) use ($gray): bool {
            $values = [];
            for ($yy = $top; $yy <= $bottom; $yy++) {
                $values[] = $gray[$yy][$col];
            }

            return $this->stdDev($values) < 6.0;
        };

        $top = $y;
        $bottom = $y + $h - 1;
        while ($top < $bottom && $rowFlat($top)) {
            $top++;
        }
        while ($bottom > $top && $rowFlat($bottom)) {
            $bottom--;
        }
        $left = $x;
        $right = $x + $w - 1;
        while ($left < $right && $colFlat($left, $top, $bottom)) {
            $left++;
        }
        while ($right > $left && $colFlat($right, $top, $bottom)) {
            $right--;
        }

        $nw = $right - $left + 1;
        $nh = $bottom - $top + 1;
        if ($nw < 6 || $nh < 6 || ($nw * $nh) > 0.92 * $w * $h) {
            return null;
        }

        return [$left, $top, $nw, $nh];
    }

    /**
     * Bounding box of non-background content when the border is a uniform color.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function uniformBorderTrim(GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $background = imagecolorat($image, 0, 0);
        $bgR = ($background >> 16) & 0xFF;
        $bgG = ($background >> 8) & 0xFF;
        $bgB = $background & 0xFF;
        $step = max(1, (int) floor(min($width, $height) / 160));

        $isBackground = static function (int $rgb) use ($bgR, $bgG, $bgB): bool {
            return abs((($rgb >> 16) & 0xFF) - $bgR) + abs((($rgb >> 8) & 0xFF) - $bgG) + abs(($rgb & 0xFF) - $bgB) < 36;
        };
        $rowIsBackground = static function (int $y) use ($image, $width, $step, $isBackground): bool {
            $misses = 0;
            for ($x = 0; $x < $width; $x += $step) {
                if (! $isBackground(imagecolorat($image, $x, $y)) && ++$misses > 2) {
                    return false;
                }
            }

            return true;
        };
        $colIsBackground = static function (int $x, int $top, int $bottom) use ($image, $step, $isBackground): bool {
            $misses = 0;
            for ($y = $top; $y <= $bottom; $y += $step) {
                if (! $isBackground(imagecolorat($image, $x, $y)) && ++$misses > 2) {
                    return false;
                }
            }

            return true;
        };

        $top = 0;
        while ($top < $height - 1 && $rowIsBackground($top)) {
            $top += $step;
        }
        $bottom = $height - 1;
        while ($bottom > $top && $rowIsBackground($bottom)) {
            $bottom -= $step;
        }
        $left = 0;
        while ($left < $width - 1 && $colIsBackground($left, $top, $bottom)) {
            $left += $step;
        }
        $right = $width - 1;
        while ($right > $left && $colIsBackground($right, $top, $bottom)) {
            $right -= $step;
        }

        $trimmedWidth = $right - $left + 1;
        $trimmedHeight = $bottom - $top + 1;
        if ($trimmedWidth < $width * 0.2 || $trimmedHeight < $height * 0.2) {
            return null;
        }
        if ($left === 0 && $top === 0 && $trimmedWidth === $width && $trimmedHeight === $height) {
            return null;
        }

        return [$left, $top, $trimmedWidth, $trimmedHeight];
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function normalize(array $values): array
    {
        $count = count($values);
        $mean = array_sum($values) / max(1, $count);
        $sumSq = 0.0;
        foreach ($values as $i => $value) {
            $values[$i] = $value - $mean;
            $sumSq += $values[$i] * $values[$i];
        }

        $norm = sqrt($sumSq);
        if ($norm < 1e-6) {
            return array_fill(0, $count, 0.0);
        }

        foreach ($values as $i => $value) {
            $values[$i] = $value / $norm;
        }

        return $values;
    }

    /**
     * @param  list<float>  $values
     */
    private function stdDev(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSq = 0.0;
        foreach ($values as $value) {
            $sumSq += ($value - $mean) ** 2;
        }

        return sqrt($sumSq / $count);
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $a
     * @param  array{0:int,1:int,2:int,3:int}  $b
     */
    private function iou(array $a, array $b): float
    {
        $x1 = max($a[0], $b[0]);
        $y1 = max($a[1], $b[1]);
        $x2 = min($a[0] + $a[2], $b[0] + $b[2]);
        $y2 = min($a[1] + $a[3], $b[1] + $b[3]);
        $intersection = max(0, $x2 - $x1) * max(0, $y2 - $y1);
        $union = ($a[2] * $a[3]) + ($b[2] * $b[3]) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
