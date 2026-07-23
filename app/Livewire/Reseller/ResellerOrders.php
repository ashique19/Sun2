<?php

namespace App\Livewire\Reseller;

use App\Models\Order;
use App\Support\ResellerAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.reseller')]
class ResellerOrders extends Component
{
    use WithPagination;

    /** @var 'progress'|'history' */
    public string $segment = 'progress';

    public function mount(string $segment = 'progress'): void
    {
        ResellerAccess::ensureReseller();
        $this->segment = in_array($segment, ['progress', 'history'], true) ? $segment : 'progress';
    }

    public function title(): string
    {
        return $this->segment === 'history' ? 'Order history' : 'Orders in progress';
    }

    public function render()
    {
        $query = Order::query()
            ->where('reseller_id', auth()->id())
            ->with(['items'])
            ->latest('placed_at')
            ->latest('id');

        if ($this->segment === 'history') {
            $query->whereIn('status', ['delivered', 'returned', 'cancelled']);
        } else {
            $query->whereIn('status', ['new', 'confirmed', 'dispatched']);
        }

        return view('livewire.reseller.reseller-orders', [
            'orders' => $query->paginate(20),
        ]);
    }
}
