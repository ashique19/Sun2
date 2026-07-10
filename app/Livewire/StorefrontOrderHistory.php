<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesProductImagePreview;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Order History - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontOrderHistory extends Component
{
    use ManagesProductImagePreview;
    use WithPagination;

    public function updatedPage(): void
    {
        $this->closeProductImage();
    }

    public function render()
    {
        $orders = auth()->user()
            ->orders()
            ->with([
                'items:id,order_id,name,quantity,product_image,product_id',
                'items.product:id,slug,name',
                'items.product.images:id,product_id,path,is_primary,sort_order',
            ])
            ->withCount('items')
            ->latest('placed_at')
            ->latest('id')
            ->paginate(10);

        return view('livewire.storefront-order-history', [
            'orders' => $orders,
        ]);
    }
}
