<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class AdminProductNavigator
{
    /**
     * Previous product in admin list order (display_order ASC, id DESC),
     * scoped to the current admin product list filters when provided.
     */
    public static function previous(Product $product, ?AdminProductListFilters $filters = null): ?Product
    {
        $filters ??= AdminProductListFilters::recall();
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        $query = Product::query();
        $filters->apply($query);

        return $query
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
     * Next product in admin list order (display_order ASC, id DESC),
     * scoped to the current admin product list filters when provided.
     */
    public static function next(Product $product, ?AdminProductListFilters $filters = null): ?Product
    {
        $filters ??= AdminProductListFilters::recall();
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        $query = Product::query();
        $filters->apply($query);

        return $query
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
}
