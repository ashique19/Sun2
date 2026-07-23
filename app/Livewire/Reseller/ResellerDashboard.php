<?php

namespace App\Livewire\Reseller;

use App\Models\Order;
use App\Services\Reseller\ResellerCommissionService;
use App\Support\ResellerAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.reseller')]
class ResellerDashboard extends Component
{
    public function mount(): void
    {
        ResellerAccess::ensureReseller();
    }

    public function title(): string
    {
        return 'Reseller dashboard';
    }

    public function render(ResellerCommissionService $commissions)
    {
        $user = auth()->user();

        $inProgress = Order::query()
            ->where('reseller_id', $user->id)
            ->whereIn('status', ['new', 'confirmed', 'dispatched'])
            ->count();

        $delivered = Order::query()
            ->where('reseller_id', $user->id)
            ->where('status', 'delivered')
            ->count();

        $pendingOrders = Order::query()
            ->where('reseller_id', $user->id)
            ->whereIn('status', ['new', 'confirmed', 'dispatched'])
            ->with('items')
            ->get();

        $pendingCommission = round($pendingOrders->sum(
            fn (Order $order) => $order->items->sum(fn ($item) => $commissions->lineCommission($item))
        ), 2);

        $recent = Order::query()
            ->where('reseller_id', $user->id)
            ->latest('placed_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.reseller.reseller-dashboard', [
            'balance' => (float) $user->reseller_balance,
            'inProgress' => $inProgress,
            'delivered' => $delivered,
            'pendingCommission' => $pendingCommission,
            'recent' => $recent,
        ]);
    }
}
