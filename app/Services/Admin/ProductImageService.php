<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImageService
{
    public function store(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $directory = $this->productDirectory($product->id);
        File::ensureDirectoryExists($directory);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.'.$extension;
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->persistUploadedFile($file, $destination);

        $path = '/img/products/'.$product->id.'/'.$filename;
        $nextOrder = (int) $product->images()->max('sort_order') + 1;
        $isPrimary = ! $product->images()->exists();

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => $alt ?: $product->name,
            'sort_order' => $nextOrder,
            'is_primary' => $isPrimary,
            'perceptual_hash' => $this->safeHash($destination),
        ]);
    }

    private function safeHash(string $absolutePath): ?string
    {
        try {
            return app(ProductImageHashService::class)->hashFile($absolutePath);
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(ProductImage $image): void
    {
        $path = $image->path;

        DB::transaction(function () use ($image, $path) {
            $wasPrimary = $image->is_primary;
            $productId = $image->product_id;

            $image->delete();

            if ($wasPrimary) {
                $replacement = ProductImage::query()
                    ->where('product_id', $productId)
                    ->orderBy('sort_order')
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_primary' => true]);
                }
            }
        });

        $this->deleteLocalFile($path);
    }

    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds): void
    {
        $ids = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $orderedIds = array_values(array_intersect($orderedIds, $ids));

        if (count($orderedIds) !== count($ids)) {
            $orderedIds = $ids;
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                ProductImage::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }

    public function moveEarlier(ProductImage $image): void
    {
        $images = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->orderBy('sort_order')
            ->get();

        $index = $images->search(fn (ProductImage $row) => $row->id === $image->id);

        if ($index === false || $index === 0) {
            return;
        }

        $ordered = $images->pluck('id')->all();
        [$ordered[$index - 1], $ordered[$index]] = [$ordered[$index], $ordered[$index - 1]];

        $this->reorder($image->product, $ordered);
    }

    public function moveLater(ProductImage $image): void
    {
        $images = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->orderBy('sort_order')
            ->get();

        $index = $images->search(fn (ProductImage $row) => $row->id === $image->id);

        if ($index === false || $index >= $images->count() - 1) {
            return;
        }

        $ordered = $images->pluck('id')->all();
        [$ordered[$index + 1], $ordered[$index]] = [$ordered[$index], $ordered[$index + 1]];

        $this->reorder($image->product, $ordered);
    }

    public function deleteProduct(Product $product): void
    {
        $directory = $this->productDirectory($product->id);

        DB::transaction(function () use ($product) {
            $product->delete();
        });

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function deleteLocalFile(string $path): void
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalized, 'img/products/')) {
            return;
        }

        $absolute = public_path($normalized);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function productDirectory(int $productId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'products', (string) $productId]));
    }

    private function persistUploadedFile(UploadedFile $file, string $destination): void
    {
        $source = $file->getRealPath();

        if (! $source || ! is_readable($source)) {
            throw new RuntimeException('Uploaded file is not readable.');
        }

        if (@File::copy($source, $destination)) {
            return;
        }

        $contents = file_get_contents($source);

        if ($contents === false || ! File::put($destination, $contents)) {
            throw new RuntimeException('Could not save uploaded image.');
        }
    }
}
