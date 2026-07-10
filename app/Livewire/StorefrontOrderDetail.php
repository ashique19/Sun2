<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontOrderDetail extends Component
{
    use ManagesProductImagePreview;

    public Order $order;

    public function mount(Order $order): void
    {
        abort_unless($order->user_id === auth()->id(), 403);

        $this->order = $order->load([
            'items.product:id,slug,name',
            'items.product.images:id,product_id,path,is_primary,sort_order',
            'coupon',
        ]);
    }

    public function title(): string
    {
        return 'Order #'.$this->order->order_number.' - Sundoritoma';
    }

    public function render()
    {
        return view('livewire.storefront-order-detail')
            ->title($this->title());
    }
}
