<?php

namespace App\Livewire\Admin;

use App\Services\Admin\SalesReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Sales by Month')]
#[Layout('components.layouts.admin')]
class AdminSalesByMonth extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(SalesReportService $reports): void
    {
        $years = $reports->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function updatedYear(): void
    {
        // Re-render with selected year (URL-synced).
    }

    public function render(SalesReportService $reports)
    {
        $years = $reports->availableYears();

        if (! in_array($this->year, $years, true)) {
            $this->year = $years[0] ?? (int) now('Asia/Dhaka')->year;
        }

        $rows = $reports->salesByMonth($this->year);

        $totals = [
            'sales_volume' => array_sum(array_column($rows, 'sales_volume')),
            'sales_value' => array_sum(array_column($rows, 'sales_value')),
            'delivered_volume' => array_sum(array_column($rows, 'delivered_volume')),
            'delivered_value' => array_sum(array_column($rows, 'delivered_value')),
        ];
        $totals['delivered_pct'] = $totals['sales_volume'] > 0
            ? round(($totals['delivered_volume'] / $totals['sales_volume']) * 100, 1)
            : null;

        return view('livewire.admin.admin-sales-by-month', [
            'years' => $years,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}
