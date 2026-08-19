<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\SharedCart;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SharedCartService
{
    /**
     * @param  list<int>  $productIds
     */
    public function createFromProductIds(array $productIds, ?int $createdBy = null): SharedCart
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            throw ValidationException::withMessages([
                'selected' => 'Select at least one product.',
            ]);
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_published', true)
            ->orderBy('name')
            ->get(['id']);

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'selected' => 'No published products were found in the selection.',
            ]);
        }

        $items = $products
            ->map(fn (Product $product) => [
                'product_id' => (int) $product->id,
                'quantity' => 1,
            ])
            ->values()
            ->all();

        return SharedCart::query()->create([
            'token' => Str::random(48),
            'created_by' => $createdBy,
            'items' => $items,
            'expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * @return Collection<int, array{product: Product, quantity: int, line_total: float}>
     */
    public function previewLines(SharedCart $sharedCart): Collection
    {
        $items = collect($sharedCart->items ?? [])
            ->filter(fn (array $item) => isset($item['product_id']))
            ->mapWithKeys(fn (array $item) => [
                (int) $item['product_id'] => max(1, (int) ($item['quantity'] ?? 1)),
            ]);

        if ($items->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->with(['images', 'category'])
            ->whereIn('id', $items->keys())
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        return $items
            ->map(function (int $quantity, int $productId) use ($products) {
                $product = $products->get($productId);

                if (! $product) {
                    return null;
                }

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'line_total' => (float) $product->price * $quantity,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<int, int>
     */
    public function sessionItems(SharedCart $sharedCart): array
    {
        return $this->previewLines($sharedCart)
            ->mapWithKeys(fn (array $line) => [$line['product']->id => $line['quantity']])
            ->all();
    }
}
