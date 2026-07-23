<?php

namespace App\Livewire\Reseller;

use App\Models\Order;
use App\Support\ResellerAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.reseller')]
class ResellerOrderShow extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        ResellerAccess::ensureCanViewOrder($order);
        $this->order = $order->load([
            'items',
            'statusHistory.changedBy',
            'coupon',
        ]);
    }

    public function title(): string
    {
        return 'Order #'.$this->order->order_number;
    }

    public function render()
    {
        return view('livewire.reseller.reseller-order-show');
    }
}
