<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class StorefrontProductNavigator
{
    /**
     * Previous published product in catalog order (display_order ASC, id DESC).
     * When the product has a category, neighbors stay within that category.
     */
    public static function previous(Product $product): ?Product
    {
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        return self::baseQuery($product)
            ->where(function (Builder $query) use ($displayOrder, $id): void {
                $query->where('display_order', '<', $displayOrder)
                    ->orWhere(function (Builder $sameOrder) use ($displayOrder, $id): void {
                        $sameOrder->where('display_order', $displayOrder)
                            ->where('id', '>', $id);
                    });
            })
            ->orderByDesc('display_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Next published product in catalog order (display_order ASC, id DESC).
     * When the product has a category, neighbors stay within that category.
     */
    public static function next(Product $product): ?Product
    {
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        return self::baseQuery($product)
            ->where(function (Builder $query) use ($displayOrder, $id): void {
                $query->where('display_order', '>', $displayOrder)
                    ->orWhere(function (Builder $sameOrder) use ($displayOrder, $id): void {
                        $sameOrder->where('display_order', $displayOrder)
                            ->where('id', '<', $id);
                    });
            })
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->first();
    }

    private static function baseQuery(Product $product): Builder
    {
        return Product::query()
            ->published()
            ->when(
                $product->category_id,
                fn (Builder $query) => $query->where('category_id', $product->category_id),
            );
    }
}
