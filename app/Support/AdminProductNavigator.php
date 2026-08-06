<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class AdminProductNavigator
{
    /**
     * Previous product in admin list order (display_order ASC, id DESC).
     */
    public static function previous(Product $product): ?Product
    {
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        return Product::query()
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
     * Next product in admin list order (display_order ASC, id DESC).
     */
    public static function next(Product $product): ?Product
    {
        $displayOrder = (int) ($product->display_order ?? 0);
        $id = (int) $product->id;

        return Product::query()
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
