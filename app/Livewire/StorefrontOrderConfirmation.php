<?php

namespace App\Livewire;

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontOrderConfirmation extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $canView = $this->ownsOrder($order)
            || (int) session('checkout.last_order_id') === (int) $order->id;

        abort_unless($canView, 403);

        $this->order = $order->load(['items', 'coupon']);
    }

    public function title(): string
    {
        return 'Order #'.$this->order->order_number.' - Sundoritoma';
    }

    public function render()
    {
        return view('livewire.storefront-order-confirmation')
            ->title($this->title());
    }

    private function ownsOrder(Order $order): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && $order->user_id !== null
            && (int) $order->user_id === (int) $userId;
    }
}
