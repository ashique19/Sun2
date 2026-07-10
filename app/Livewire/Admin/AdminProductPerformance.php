<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use App\Services\Admin\SalesReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Product Performance')]
#[Layout('components.layouts.admin')]
class AdminProductPerformance extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        $this->product = $product->load([
            'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->limit(1),
            'category:id,name',
        ]);
    }

    public function render(SalesReportService $reports)
    {
        $rows = $reports->productPerformance($this->product, 48);

        $totals = [
            'sales_volume' => array_sum(array_column($rows, 'sales_volume')),
            'sales_value' => array_sum(array_column($rows, 'sales_value')),
            'delivered_volume' => array_sum(array_column($rows, 'delivered_volume')),
            'delivered_value' => array_sum(array_column($rows, 'delivered_value')),
        ];
        $totals['delivered_pct'] = $totals['sales_volume'] > 0
            ? round(($totals['delivered_volume'] / $totals['sales_volume']) * 100, 1)
            : null;

        return view('livewire.admin.admin-product-performance', [
            'rows' => $rows,
            'totals' => $totals,
        ])->title('Performance — '.$this->product->name);
    }
}
