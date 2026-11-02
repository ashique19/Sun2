<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Support\StorefrontAssets;

class InboxCatalogSearch
{
    /**
     * @return list<array{id: int, name: string, sku: ?string, price: float, stock_quantity: int, image_url: ?string}>
     */
    public function mappingSuggestions(
        string $term,
        string $categoryId,
        string $stock,
        string $priceMin,
        string $priceMax,
        int $limit = 12,
    ): array {
        [$min, $max] = $this->normalizedPriceRange($priceMin, $priceMax);
        $term = trim($term);

        return Product::query()
            ->published()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->when($term !== '', fn ($q) => $q->searchTerm($term))
            ->when($categoryId !== '', fn ($q) => $q->where('category_id', (int) $categoryId))
            ->when($stock === 'in', fn ($q) => $q->where('stock_quantity', '>', 0))
            ->when($stock === 'out', fn ($q) => $q->where('stock_quantity', '<=', 0))
            ->when($min !== null, fn ($q) => $q->where('price', '>=', $min))
            ->when($max !== null, fn ($q) => $q->where('price', '<=', $max))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => StorefrontAssets::url($product->primaryImagePath()),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, price: float, sku: ?string, category: ?string, image_url: ?string, priced_image_url: ?string, has_priced_image: bool}>
     */
    public function pickerResults(
        string $term,
        string $categoryId,
        string $priceMin,
        string $priceMax,
        int $limit = 24,
    ): array {
        [$min, $max] = $this->normalizedPriceRange($priceMin, $priceMax);
        $term = trim($term);

        return Product::query()
            ->with([
                'category:id,name',
                'images' => fn ($q) => $q->orderBy('sort_order')->limit(1),
            ])
            ->when($term !== '', fn ($q) => $q->searchTerm($term, includePrice: false))
            ->when($min !== null, fn ($q) => $q->where('price', '>=', $min))
            ->when($max !== null, fn ($q) => $q->where('price', '<=', $max))
            ->when($categoryId !== '', fn ($q) => $q->where('category_id', (int) $categoryId))
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Product $product) {
                $pricedPath = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';
                $hasPriced = $pricedPath !== '';
                $previewPath = $hasPriced ? $pricedPath : $product->primaryImagePath();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'image_url' => StorefrontAssets::url($previewPath),
                    'priced_image_url' => $hasPriced ? StorefrontAssets::url($pricedPath) : null,
                    'has_priced_image' => $hasPriced,
                ];
            })
            ->all();
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    public function normalizedPriceRange(string $min, string $max): array
    {
        $priceMin = $this->normalizedPriceBound($min);
        $priceMax = $this->normalizedPriceBound($max);

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        return [$priceMin, $priceMax];
    }

    public function normalizedPriceBound(string $value): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', trim($value)) ?? '';

        if ($digits === '' || ! is_numeric($digits)) {
            return null;
        }

        return max(0, (float) $digits);
    }
}
