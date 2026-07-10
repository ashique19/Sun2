<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\Wishlist;

class WishlistService
{
    public function has(int $userId, int $productId): bool
    {
        return Wishlist::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    public function toggle(int $userId, int $productId): bool
    {
        $existing = Wishlist::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        Wishlist::query()->create([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        return true;
    }

    public function count(int $userId): int
    {
        return Wishlist::query()->where('user_id', $userId)->count();
    }
}
