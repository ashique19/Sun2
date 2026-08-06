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
        return Product::query()
            ->where(function (Builder $query) use ($product): void {
                $query->where('display_order', '<', $product->display_order)
                    ->orWhere(function (Builder $sameOrder) use ($product): void {
                        $sameOrder->where('display_order', $product->display_order)
                            ->where('id', '>', $product->id);
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
        return Product::query()
            ->where(function (Builder $query) use ($product): void {
                $query->where('display_order', '>', $product->display_order)
                    ->orWhere(function (Builder $sameOrder) use ($product): void {
                        $sameOrder->where('display_order', $product->display_order)
                            ->where('id', '<', $product->id);
                    });
            })
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->first();
    }
}
