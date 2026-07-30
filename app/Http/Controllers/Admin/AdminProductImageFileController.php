<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\AdminAccess;
use App\Support\StorefrontAssets;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class AdminProductImageFileController
{
    /**
     * Same-origin image stream for the product gallery editor (Cropper + canvas).
     */
    public function __invoke(Product $product, ProductImage $image): Response
    {
        AdminAccess::ensureStaffAdmin();

        abort_unless((int) $image->product_id === (int) $product->id, 404);

        $path = ltrim(str_replace('\\', '/', (string) $image->path), '/');
        abort_if($path === '' || str_contains($path, '..'), 404);

        try {
            if ($local = $this->localResponse($path)) {
                return $local;
            }

            foreach ($this->candidateAbsolutePaths($path) as $absolute) {
                if ($local = $this->localAbsoluteResponse($absolute)) {
                    return $local;
                }
            }

            $remote = StorefrontAssets::largestAvailableUrl($image->path)
                ?? StorefrontAssets::url($image->path);

            abort_if(! $remote || ! str_starts_with($remote, 'http'), 404);

            $response = Http::timeout(20)
                ->withHeaders(['Accept' => 'image/*,*/*'])
                ->get($remote);

            abort_unless($response->successful(), 404, 'Product image could not be fetched.');

            $contentType = explode(';', (string) ($response->header('Content-Type') ?: 'image/jpeg'))[0];

            return response($response->body(), 200, [
                'Content-Type' => $contentType !== '' ? $contentType : 'image/jpeg',
                'Cache-Control' => 'private, max-age=120',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable) {
            abort(404, 'Product image could not be loaded.');
        }
    }

    private function localResponse(string $relative): ?Response
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return null;
        }

        return $this->localAbsoluteResponse(public_path($relative));
    }

    private function localAbsoluteResponse(string $absolute): ?Response
    {
        if (! is_file($absolute) || ! is_readable($absolute)) {
            return null;
        }

        $contentType = mime_content_type($absolute) ?: 'image/jpeg';
        $contentType = explode(';', $contentType)[0];

        return response((string) file_get_contents($absolute), 200, [
            'Content-Type' => $contentType !== '' ? $contentType : 'image/jpeg',
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Prefer largest variant when the DB path points at a smaller sibling.
     *
     * @return list<string>
     */
    private function candidateAbsolutePaths(string $relative): array
    {
        $paths = [public_path($relative)];

        if (preg_match('/^(img\/products\/\d+\/.+?)_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $relative, $matches)) {
            foreach (['lg', 'md', 'sm', 'xs'] as $variant) {
                $paths[] = public_path($matches[1].'_'.$variant.'.jpg');
            }
        }

        return array_values(array_unique($paths));
    }
}
