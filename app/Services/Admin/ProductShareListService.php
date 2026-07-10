<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\ProductShareList;
use App\Support\StorefrontAssets;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductShareListService
{
    /**
     * @param  list<int>  $orderIds
     */
    public function createFromOrders(array $orderIds, ?int $createdBy = null): ProductShareList
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            throw ValidationException::withMessages([
                'selected' => 'Select at least one order.',
            ]);
        }

        $orders = Order::query()
            ->with([
                'items:id,order_id,product_id,name,quantity,product_image',
                'items.product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            ])
            ->whereIn('id', $orderIds)
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'selected' => 'No selected orders were found.',
            ]);
        }

        /** @var array<string, array{key:string,product_id:?int,name:string,quantity:int,image:?string}> $grouped */
        $grouped = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $productId = $item->product_id ? (int) $item->product_id : null;
                $name = (string) ($item->product?->name ?: $item->name);
                $image = StorefrontAssets::mediumUrl($item->product?->primaryImagePath())
                    ?? StorefrontAssets::mediumUrl($item->product_image)
                    ?? StorefrontAssets::mediumUrl($item->imageUrl());
                $groupKey = $productId
                    ? 'p:'.$productId
                    : 'n:'.md5(mb_strtolower($name).'|'.($image ?? ''));

                if (! isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [
                        'key' => (string) Str::uuid(),
                        'product_id' => $productId,
                        'name' => $name,
                        'quantity' => 0,
                        'image' => $image,
                    ];
                }

                $grouped[$groupKey]['quantity'] += (int) $item->quantity;
            }
        }

        $items = array_values($grouped);

        usort($items, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ProductShareList::query()->create([
            'token' => Str::random(48),
            'created_by' => $createdBy,
            'items' => $items,
            'expires_at' => now()->addHours(24),
        ]);
    }
}
