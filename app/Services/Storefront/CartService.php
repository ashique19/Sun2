<?php

namespace App\Services\Storefront;

use App\Models\Product;
use Illuminate\Support\Collection;

class CartService
{
    private const SESSION_KEY = 'storefront.cart';

    /** @return array<int, int> product_id => quantity */
    public function items(): array
    {
        return session(self::SESSION_KEY, []);
    }

    public function count(): int
    {
        return array_sum($this->items());
    }

    public function add(int $productId, int $quantity = 1): void
    {
        $items = $this->items();
        $items[$productId] = ($items[$productId] ?? 0) + max(1, $quantity);
        session([self::SESSION_KEY => $items]);
    }

    public function update(int $productId, int $quantity): void
    {
        $items = $this->items();

        if ($quantity <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = $quantity;
        }

        session([self::SESSION_KEY => $items]);
    }

    public function remove(int $productId): void
    {
        $items = $this->items();
        unset($items[$productId]);
        session([self::SESSION_KEY => $items]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** @return Collection<int, array{product: Product, quantity: int, line_total: float}> */
    public function lines(): Collection
    {
        $items = $this->items();

        if ($items === []) {
            return collect();
        }

        $products = Product::query()
            ->with(['images', 'category'])
            ->whereIn('id', array_keys($items))
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        return collect($items)
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

    public function subtotal(): float
    {
        return (float) $this->lines()->sum('line_total');
    }
}
