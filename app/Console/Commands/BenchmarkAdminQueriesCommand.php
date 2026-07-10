<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\AdminDashboardMetrics;
use App\Support\AdminOrderSegment;
use Illuminate\Console\Command;

class BenchmarkAdminQueriesCommand extends Command
{
    protected $signature = 'bench:admin-queries {--rounds=3 : Timing rounds to average}';

    protected $description = 'Time common admin/storefront queries (dev performance check)';

    public function handle(): int
    {
        $rounds = max(1, (int) $this->option('rounds'));

        $this->info('Benchmarking ('.$rounds.' round(s))…');
        $this->newLine();

        $results = [
            'New orders list (paginate 20 + items)' => fn () => $this->benchNewOrders(),
            'Dispatched list stored tracking (no live API)' => fn () => $this->benchDispatchedStored(),
            'Category PLP (24 products + listingImage)' => fn () => $this->benchCategoryPlp(),
            'Dashboard segment counts' => fn () => AdminOrderSegment::counts(fresh: true),
            'Dashboard daily totals (30d)' => fn () => AdminDashboardMetrics::dailyTotals(30, fresh: true),
        ];

        $rows = [];

        foreach ($results as $label => $callback) {
            $times = [];

            for ($i = 0; $i < $rounds; $i++) {
                $start = hrtime(true);
                $callback();
                $times[] = (hrtime(true) - $start) / 1_000_000;
            }

            $avg = array_sum($times) / count($times);
            $rows[] = [$label, number_format($avg, 2).' ms'];
        }

        $this->table(['Query', 'Avg time'], $rows);

        return self::SUCCESS;
    }

    private function benchNewOrders(): void
    {
        AdminOrderSegment::apply(Order::query(), 'new')
            ->with(['courier:id,name,slug', 'items:id,order_id,name,quantity,product_image,product_id'])
            ->latest('placed_at')
            ->latest('id')
            ->simplePaginate(20);
    }

    private function benchDispatchedStored(): void
    {
        AdminOrderSegment::apply(Order::query(), 'dispatched')
            ->with(['courier:id,name,slug', 'items:id,order_id,name,quantity,product_image,product_id', 'courierLogs'])
            ->latest('placed_at')
            ->latest('id')
            ->simplePaginate(20);
    }

    private function benchCategoryPlp(): void
    {
        $categoryId = Category::query()->where('is_active', true)->value('id');

        if (! $categoryId) {
            Product::query()
                ->with(['listingImage', 'category:id,name,slug'])
                ->published()
                ->paginate(24);

            return;
        }

        Product::query()
            ->with(['listingImage', 'category:id,name,slug'])
            ->published()
            ->where('category_id', $categoryId)
            ->paginate(24);
    }
}
