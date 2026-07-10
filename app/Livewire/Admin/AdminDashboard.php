<?php

namespace App\Livewire\Admin;

use App\Support\AdminDashboardMetrics;
use App\Support\AdminOrderSegment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admin Dashboard')]
#[Layout('components.layouts.admin')]
class AdminDashboard extends Component
{
    public function render()
    {
        $segmentCounts = AdminOrderSegment::counts();
        $dailyTotals = AdminDashboardMetrics::dailyTotals();

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
        ]);
    }
}
