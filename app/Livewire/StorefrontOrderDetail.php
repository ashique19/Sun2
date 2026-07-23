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
        abort_unless($this->canViewOrder($order), 403);

        $this->order = $order->load([
            'items.product:id,slug,name',
            'items.product.images:id,product_id,path,is_primary,sort_order',
            'coupon',
            'adjustments',
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

    private function canViewOrder(Order $order): bool
    {
        $userId = auth()->id();

        if ($userId !== null
            && $order->user_id !== null
            && (int) $order->user_id === (int) $userId) {
            return true;
        }

        // Allow the just-placed confirmation CTA in this browser session.
        return $userId !== null
            && (int) session('checkout.last_order_id') === (int) $order->id;
    }
}
