<?php

namespace App\Services\Social;

use Illuminate\Support\Str;

class SocialPostCollageService
{
    /**
     * @param  array<int, string>  $imagePathsOrUrls
     * @return string Relative path under public/ (e.g. img/social-posts/collage_123.jpg)
     */
    public function compose(array $imagePathsOrUrls, string $outputRelativePath): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension is required for collage layout.');
        }

        $imagePathsOrUrls = array_values(array_filter($imagePathsOrUrls, fn ($x) => is_string($x) && $x !== ''));
        if ($imagePathsOrUrls === []) {
            throw new \RuntimeException('No images provided for collage composition.');
        }

        $tileSize = 300;
        $gap = 8;
        $columns = min(3, count($imagePathsOrUrls));
        $rows = (int) ceil(count($imagePathsOrUrls) / $columns);

        $canvasW = $columns * $tileSize + ($columns - 1) * $gap;
        $canvasH = $rows * $tileSize + ($rows - 1) * $gap;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        if ($canvas === false) {
            throw new \RuntimeException('Unable to create GD canvas.');
        }

        // Cream-ish background to match the storefront palette.
        $bg = imagecolorallocate($canvas, 250, 246, 239);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $bg);

        foreach (array_values($imagePathsOrUrls) as $index => $pathOrUrl) {
            $tileX = ($index % $columns) * ($tileSize + $gap);
            $tileY = intdiv($index, $columns) * ($tileSize + $gap);

            $localPath = $this->resolveLocalPath($pathOrUrl);
            $img = $this->createImageResource($localPath);

            if (! $img) {
                continue;
            }

            $srcW = imagesx($img);
            $srcH = imagesy($img);

            // Center-crop to tileSize x tileSize.
            $srcAspect = $srcW / max(1, $srcH);
            $tileAspect = 1.0; // square

            if ($srcAspect > $tileAspect) {
                // wider than square: crop sides
                $cropH = $srcH;
                $cropW = (int) round($srcH * $tileAspect);
                $cropX = (int) floor(($srcW - $cropW) / 2);
                $cropY = 0;
            } else {
                // taller than square: crop top/bottom
                $cropW = $srcW;
                $cropH = (int) round($srcW / $tileAspect);
                $cropX = 0;
                $cropY = (int) floor(($srcH - $cropH) / 2);
            }

            imagecopyresampled(
                $canvas,
                $img,
                $tileX,
                $tileY,
                $cropX,
                $cropY,
                $tileSize,
                $tileSize,
                $cropW,
                $cropH
            );

            imagedestroy($img);
        }

        $publicDir = public_path();
        $outAbsolute = $publicDir.DIRECTORY_SEPARATOR.$outputRelativePath;
        if (! str_starts_with($outAbsolute, $publicDir)) {
            throw new \RuntimeException('Refusing to write collage outside public/.');
        }

        $outDir = dirname($outAbsolute);
        if (! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        imagejpeg($canvas, $outAbsolute, 86);
        imagedestroy($canvas);

        return $outputRelativePath;
    }

    private function resolveLocalPath(string $pathOrUrl): string
    {
        if (Str::startsWith($pathOrUrl, ['http://', 'https://'])) {
            $hash = md5($pathOrUrl);
            $tmp = sys_get_temp_dir().'/social-collage-'.$hash;
            if (is_file($tmp)) {
                return $tmp;
            }

            $bytes = @file_get_contents($pathOrUrl);
            if (! is_string($bytes) || $bytes === '') {
                return $pathOrUrl;
            }

            file_put_contents($tmp, $bytes);

            return $tmp;
        }

        // Store as relative-to-public, but DB often stores without a leading slash.
        $relative = ltrim(str_replace('\\', '/', $pathOrUrl), '/');
        return public_path($relative);
    }

    /**
     * @return resource|null
     */
    private function createImageResource(string $localPath)
    {
        if (! is_file($localPath)) {
            return null;
        }

        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => imagecreatefromjpeg($localPath),
            'png' => imagecreatefrompng($localPath),
            default => imagecreatefromjpeg($localPath), // best-effort
        };
    }
}

