<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use App\Services\Admin\SalesReportService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminProductShow extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->product = $product->load([
            'category:id,name',
            'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
        ]);
    }

    public function title(): string
    {
        return $this->product->name;
    }

    public function render(SalesReportService $reports)
    {
        $summary = $reports->productSummary($this->product);
        $channels = $reports->productChannelBreakdown($this->product);
        $rows = $reports->productPerformance($this->product, 48);

        $monthTotals = [
            'sales_volume' => array_sum(array_column($rows, 'sales_volume')),
            'sales_value' => array_sum(array_column($rows, 'sales_value')),
            'delivered_volume' => array_sum(array_column($rows, 'delivered_volume')),
            'delivered_value' => array_sum(array_column($rows, 'delivered_value')),
        ];
        $monthTotals['delivered_pct'] = $monthTotals['sales_volume'] > 0
            ? round(($monthTotals['delivered_volume'] / $monthTotals['sales_volume']) * 100, 1)
            : null;

        return view('livewire.admin.admin-product-show', [
            'summary' => $summary,
            'channels' => $channels,
            'rows' => $rows,
            'monthTotals' => $monthTotals,
        ])->title($this->title());
    }
}
