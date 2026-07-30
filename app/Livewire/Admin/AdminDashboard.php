<?php

namespace App\Livewire\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Order;
use App\Services\Admin\AdminAttentionService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Support\AdminAccess;
use App\Support\AdminDashboardMetrics;
use App\Support\AdminOrderSegment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admin Dashboard')]
#[Layout('components.layouts.admin')]
class AdminDashboard extends Component
{
    /** @var array<int|string, string> */
    public array $pendingCourierCharges = [];

    public ?string $courierChargeMessage = null;

    public function mount(): void
    {
        if (AdminAccess::isModeratorOnly()) {
            $this->redirect(route('admin.orders.new'), navigate: true);
        }
    }

    public function markResolved(int $itemId): void
    {
        $item = AdminAttentionItem::findOrFail($itemId);
        app(AdminAttentionService::class)->markAsResolved($item, 'Resolved from dashboard');

        $this->dispatch('attention-item-resolved');
    }

    public function confirmCourierCharge(int $orderId, OrderCourierChargeSync $courierChargeSync): void
    {
        AdminAccess::ensureStaffAdmin();

        $order = Order::query()
            ->whereKey($orderId)
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->firstOrFail();

        $raw = $this->pendingCourierCharges[$orderId] ?? (string) (int) round((float) $order->courier_charge);

        $this->validate([
            'pendingCourierCharges.'.$orderId => ['required', 'numeric', 'min:0'],
        ], [], [
            'pendingCourierCharges.'.$orderId => 'courier charge',
        ]);

        $courierChargeSync->confirm(
            order: $order,
            amount: (float) $raw,
            actor: auth()->user(),
        );

        unset($this->pendingCourierCharges[$orderId]);
        $this->courierChargeMessage = 'Courier charge confirmed for '.$order->name.'.';
    }

    public function render()
    {
        if (AdminAccess::isModeratorOnly()) {
            return view('livewire.admin.admin-dashboard', [
                'segments' => [],
                'segmentCounts' => [],
                'monthlyTotals' => [],
                'periodTotals' => [
                    'order_qty' => 0,
                    'order_value' => 0,
                    'delivery_qty' => 0,
                    'delivery_value' => 0,
                ],
                'attentionSummary' => [
                    'unresolved_count' => 0,
                    'unresolved_items' => collect(),
                    'recent_resolved' => collect(),
                    'unresolved_by_type' => [],
                ],
                'unconfirmedCourierCharges' => collect(),
            ]);
        }

        $segmentCounts = AdminOrderSegment::counts();
        $monthlyTotals = AdminDashboardMetrics::dailyTotals();

        $attentionService = app(AdminAttentionService::class);
        $attentionSummary = $attentionService->getDashboardSummary();
        $attentionSummary['unresolved_items'] = AdminAttentionItem::unresolved()
            ->with('order')
            ->latest()
            ->limit(10)
            ->get();

        $periodTotals = [
            'order_qty' => (int) array_sum(array_column(array_column($monthlyTotals, 'totals'), 'order_qty')),
            'order_value' => (float) array_sum(array_column(array_column($monthlyTotals, 'totals'), 'order_value')),
            'delivery_qty' => (int) array_sum(array_column(array_column($monthlyTotals, 'totals'), 'delivery_qty')),
            'delivery_value' => (float) array_sum(array_column(array_column($monthlyTotals, 'totals'), 'delivery_value')),
        ];

        $unconfirmedCourierCharges = Order::query()
            ->with(['courier:id,name,slug,charge,osd_charge'])
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'order_number', 'name', 'city', 'courier_id', 'courier_tracker', 'courier_charge', 'dispatch_date', 'status']);

        foreach ($unconfirmedCourierCharges as $order) {
            if (! array_key_exists($order->id, $this->pendingCourierCharges)) {
                $this->pendingCourierCharges[$order->id] = (string) (int) round((float) $order->courier_charge);
            }
        }

        return view('livewire.admin.admin-dashboard', [
            'segments' => AdminOrderSegment::SEGMENTS,
            'segmentCounts' => $segmentCounts,
            'monthlyTotals' => $monthlyTotals,
            'periodTotals' => $periodTotals,
            'attentionSummary' => $attentionSummary,
            'unconfirmedCourierCharges' => $unconfirmedCourierCharges,
        ]);
    }
}
