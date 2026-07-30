<?php

namespace App\Livewire\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Order;
use App\Services\Admin\AdminAttentionService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPackagingCost;
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

    /** @var array<int|string, string> */
    public array $pendingPackagingCosts = [];

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

    public function applyCourierChargePreset(int $orderId, int $amount): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($amount < 0) {
            return;
        }

        $exists = Order::query()
            ->whereKey($orderId)
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->exists();

        if (! $exists) {
            return;
        }

        $this->pendingCourierCharges[$orderId] = (string) $amount;
        $this->resetValidation('pendingCourierCharges.'.$orderId);
    }

    public function confirmCourierCharge(
        int $orderId,
        OrderCourierChargeSync $courierChargeSync,
        OrderPackagingCost $packagingCost,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $order = Order::query()
            ->with('items:id,order_id,quantity')
            ->whereKey($orderId)
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->firstOrFail();

        $chargeRaw = $this->pendingCourierCharges[$orderId]
            ?? (string) (int) round($courierChargeSync->suggestedConfirmAmount($order));
        $packagingRaw = $this->pendingPackagingCosts[$orderId]
            ?? (string) (int) round($packagingCost->suggestedAmount($order));

        $this->pendingCourierCharges[$orderId] = $chargeRaw;
        $this->pendingPackagingCosts[$orderId] = $packagingRaw;

        $this->validate([
            'pendingCourierCharges.'.$orderId => ['required', 'numeric', 'min:0'],
            'pendingPackagingCosts.'.$orderId => ['required', 'numeric', 'min:0'],
        ], [], [
            'pendingCourierCharges.'.$orderId => 'courier charge',
            'pendingPackagingCosts.'.$orderId => 'packaging cost',
        ]);

        $packagingCost->apply($order, (float) $packagingRaw);

        $courierChargeSync->confirm(
            order: $order->fresh(),
            amount: (float) $chargeRaw,
            actor: auth()->user(),
        );

        unset($this->pendingCourierCharges[$orderId], $this->pendingPackagingCosts[$orderId]);
        $this->courierChargeMessage = 'Courier charge and packaging updated for '.$order->name.'.';
    }

    public function render(OrderCourierChargeSync $courierChargeSync, OrderPackagingCost $packagingCost)
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
                'courierChargeAreaLabels' => [],
                'courierChargeQuickAmounts' => [],
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
            ->with([
                'courier:id,name,slug,charge,osd_charge',
                'items:id,order_id,quantity',
            ])
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'order_number', 'name', 'city', 'area', 'courier_id', 'courier_tracker', 'courier_consignment_id', 'courier_charge', 'packaging_cost', 'dispatch_date', 'status']);

        $courierChargeAreaLabels = [];
        $courierChargeQuickAmounts = [];

        foreach ($unconfirmedCourierCharges as $order) {
            $suggested = $courierChargeSync->suggestedConfirmAmount($order);
            $courierChargeAreaLabels[$order->id] = $courierChargeSync->areaRateLabel($order);
            $courierChargeQuickAmounts[$order->id] = $courierChargeSync->quickConfirmAmounts($order);

            if (! array_key_exists($order->id, $this->pendingCourierCharges)) {
                $this->pendingCourierCharges[$order->id] = (string) (int) round($suggested);
            }

            if (! array_key_exists($order->id, $this->pendingPackagingCosts)) {
                $this->pendingPackagingCosts[$order->id] = (string) (int) round($packagingCost->suggestedAmount($order));
            }
        }

        return view('livewire.admin.admin-dashboard', [
            'segments' => AdminOrderSegment::SEGMENTS,
            'segmentCounts' => $segmentCounts,
            'monthlyTotals' => $monthlyTotals,
            'periodTotals' => $periodTotals,
            'attentionSummary' => $attentionSummary,
            'unconfirmedCourierCharges' => $unconfirmedCourierCharges,
            'courierChargeAreaLabels' => $courierChargeAreaLabels,
            'courierChargeQuickAmounts' => $courierChargeQuickAmounts,
        ]);
    }
}
