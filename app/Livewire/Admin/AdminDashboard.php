<?php

namespace App\Livewire\Admin;

use App\Models\AdminAttentionItem;
use App\Services\Admin\AdminAttentionService;
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

    public function render()
    {
        if (AdminAccess::isModeratorOnly()) {
            return view('livewire.admin.admin-dashboard', [
                'segments' => [],
                'segmentCounts' => [],
                'dailyTotals' => [],
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
            ]);
        }

        $segmentCounts = AdminOrderSegment::counts();
        $dailyTotals = AdminDashboardMetrics::dailyTotals();

        $attentionService = app(AdminAttentionService::class);
        $attentionSummary = $attentionService->getDashboardSummary();
        $attentionSummary['unresolved_items'] = AdminAttentionItem::unresolved()
            ->with('order')
            ->latest()
            ->limit(10)
            ->get();

        $periodTotals = [
            'order_qty' => array_sum(array_column($dailyTotals, 'order_qty')),
            'order_value' => array_sum(array_column($dailyTotals, 'order_value')),
            'delivery_qty' => array_sum(array_column($dailyTotals, 'delivery_qty')),
            'delivery_value' => array_sum(array_column($dailyTotals, 'delivery_value')),
        ];

        return view('livewire.admin.admin-dashboard', [
            'segments' => AdminOrderSegment::SEGMENTS,
            'segmentCounts' => $segmentCounts,
            'dailyTotals' => $dailyTotals,
            'periodTotals' => $periodTotals,
            'attentionSummary' => $attentionSummary,
        ]);
    }
}
