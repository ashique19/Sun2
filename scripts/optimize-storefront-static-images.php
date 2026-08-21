<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

/**
 * One-shot optimizer for legacy homepage static images (hero + category thumbs).
 * Generates _md/_sm/_xs JPEG siblings and recompresses oversized masters.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function loadImage(string $absolute): ?GdImage
{
    $info = @getimagesize($absolute);
    if (! is_array($info)) {
        return null;
    }

    return match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
        IMAGETYPE_PNG => @imagecreatefrompng($absolute),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : null,
        default => null,
    };
}

function resizeToMaxEdge(GdImage $source, int $maxEdge): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $longest = max($width, $height);

    if ($longest <= $maxEdge) {
        $copy = imagecreatetruecolor($width, $height);
        imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);

        return $copy;
    }

    $scale = $maxEdge / $longest;
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));
    $dest = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

    return $dest;
}

function writeJpeg(GdImage $image, string $absolute, int $quality = 82): void
{
    imagejpeg($image, $absolute, $quality);
}

function optimizeJpegMaster(string $absolute, int $maxEdge = 1600, int $quality = 82, int $minBytes = 350_000): void
{
    if (! is_file($absolute) || filesize($absolute) < $minBytes) {
        return;
    }

    $source = loadImage($absolute);
    if (! $source) {
        return;
    }

    $resized = resizeToMaxEdge($source, $maxEdge);
    writeJpeg($resized, $absolute, $quality);
    imagedestroy($resized);
    imagedestroy($source);
}

function ensureVariants(string $absolute, array $edges = ['md' => 800, 'sm' => 400, 'xs' => 200]): void
{
    if (! is_file($absolute)) {
        return;
    }

    $source = loadImage($absolute);
    if (! $source) {
        return;
    }

    foreach ($edges as $suffix => $edge) {
        $variant = preg_replace('/(\.[a-zA-Z0-9]+)$/', '_'.$suffix.'.jpg', $absolute);
        if (! is_string($variant)) {
            continue;
        }

        if (is_file($variant) && filesize($variant) > 0 && filesize($variant) < filesize($absolute)) {
            continue;
        }

        $resized = resizeToMaxEdge($source, $edge);
        writeJpeg($resized, $variant, 82);
        imagedestroy($resized);
        echo 'wrote '.str_replace(base_path().'/', '', $variant).' ('.filesize($variant).")\n";
    }

    imagedestroy($source);
}

$heroDir = public_path('img/hero');
foreach (File::glob($heroDir.'/*.jpg') as $file) {
    if (preg_match('/_(xs|sm|md|lg)\.jpg$/i', $file)) {
        continue;
    }
    optimizeJpegMaster($file, maxEdge: 1600, quality: 80, minBytes: 200_000);
    ensureVariants($file, ['md' => 1200, 'sm' => 800, 'xs' => 480]);
    echo 'hero '.basename($file).' => '.filesize($file)." bytes\n";
}

$categoryDir = public_path('img/categories');
foreach (File::glob($categoryDir.'/*.{jpg,JPG,jpeg,JPEG}', GLOB_BRACE) ?: File::files($categoryDir) as $file) {
    $absolute = is_string($file) ? $file : $file->getPathname();
    if (! preg_match('/\.(jpe?g)$/i', $absolute)) {
        continue;
    }
    if (preg_match('/_(xs|sm|md|lg)\.(jpe?g)$/i', $absolute)) {
        continue;
    }
    // Skip already-small assets.
    if (filesize($absolute) < 80_000) {
        continue;
    }
    optimizeJpegMaster($absolute, maxEdge: 900, quality: 80, minBytes: 80_000);
    ensureVariants($absolute, ['md' => 600, 'sm' => 400, 'xs' => 200]);
    echo 'category '.basename($absolute).' => '.filesize($absolute)." bytes\n";
}

// Shrink oversized PNG logo/favicon used in chrome.
function optimizePngDownscale(string $absolute, int $maxEdge, int $quality = 7): void
{
    if (! is_file($absolute)) {
        return;
    }
    $source = @imagecreatefrompng($absolute);
    if (! $source) {
        return;
    }
    $resized = resizeToMaxEdge($source, $maxEdge);
    imagesavealpha($resized, true);
    imagepng($resized, $absolute, $quality);
    imagedestroy($resized);
    imagedestroy($source);
    echo 'png '.basename($absolute).' => '.filesize($absolute)." bytes\n";
}

optimizePngDownscale(public_path('img/settings/logo.png'), 320, 6);
optimizePngDownscale(public_path('img/settings/favicon.png'), 64, 6);

echo "done\n";
