<?php

namespace App\Livewire\Concerns;

use App\Models\OrderProduct;

trait ManagesProductImagePreview
{
    public function openProductImage(int $orderProductId): void
    {
        $item = OrderProduct::query()
            ->with([
                'product:id,slug,name',
                'product.images:id,product_id,path,is_primary,sort_order',
            ])
            ->find($orderProductId);

        if (! $item || ! $item->imageUrl()) {
            return;
        }

        $this->dispatch(
            'open-product-image',
            imageUrl: $item->previewImageUrl(),
            productName: $item->displayName(),
            productUrl: $item->storefrontUrl() ?? '',
        );
    }

    public function closeProductImage(): void
    {
        $this->dispatch('close-product-image');
    }
}
