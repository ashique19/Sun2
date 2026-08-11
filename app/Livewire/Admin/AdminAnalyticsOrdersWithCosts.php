<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\ProductUnitCostService;
use App\Support\AdminAccess;
use App\Support\StorefrontAssets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Analytics · All orders with costs')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsOrdersWithCosts extends Component
{
    use WithPagination;

    /** @var list<string> */
    private const INLINE_FIELDS = ['cogs', 'packaging_cost', 'courier_charge'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $zeroRevenue = false;

    #[Url]
    public bool $zeroCogs = false;

    #[Url]
    public bool $zeroPackaging = false;

    #[Url]
    public bool $zeroCourier = false;

    #[Url]
    public bool $zeroCod = false;

    #[Url]
    public bool $zeroDirect = false;

    #[Url]
    public bool $zeroProfit = false;

    public ?int $editingOrderId = null;

    public ?string $editingField = null;

    public string $editingValue = '';

    public bool $cogsModalOpen = false;

    public ?int $cogsModalOrderId = null;

    public string $cogsModalOrderNumber = '';

    /**
     * @var list<array{
     *     key: string,
     *     order_product_id: int,
     *     product_id: int|null,
     *     name: string,
     *     thumb: string|null,
     *     qty: int,
     *     purchase_price: string,
     *     other_cost: string,
     *     has_materials: bool,
     *     edit_url: string|null
     * }>
     */
    public array $cogsModalRows = [];

    public ?string $cogsModalMessage = null;

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroRevenue(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroCogs(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroPackaging(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroCourier(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroCod(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroDirect(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function updatedZeroProfit(): void
    {
        $this->resetPage();
        $this->cancelInlineEdit();
    }

    public function startInlineEdit(int $orderId, string $field, string $value = ''): void
    {
        if (! in_array($field, self::INLINE_FIELDS, true)) {
            return;
        }

        if ($field === 'cogs' && (float) $value < 0.01) {
            $this->openCogsModal($orderId);

            return;
        }

        Order::query()->findOrFail($orderId);

        $this->editingOrderId = $orderId;
        $this->editingField = $field;
        $this->editingValue = $value;
        $this->resetValidation();
    }

    public function cancelInlineEdit(): void
    {
        $this->editingOrderId = null;
        $this->editingField = null;
        $this->editingValue = '';
        $this->resetValidation();
    }

    public function saveInlineEdit(): void
    {
        if ($this->editingOrderId === null || $this->editingField === null) {
            return;
        }

        $field = $this->editingField;

        if (! in_array($field, self::INLINE_FIELDS, true)) {
            $this->cancelInlineEdit();

            return;
        }

        $this->validate([
            'editingValue' => ['required', 'numeric', 'min:0'],
        ]);

        $order = Order::query()->with(['items', 'courier'])->findOrFail($this->editingOrderId);
        $amount = round((float) $this->editingValue, 2);

        if ($field === 'cogs') {
            if ($order->cogs() < 0.01) {
                $this->openCogsModal($order->id);
                $this->cancelInlineEdit();

                return;
            }

            $this->applyCogsOverride($order, $amount);
        } else {
            $order->update([$field => $amount]);
        }

        $this->cancelInlineEdit();
    }

    public function openCogsModal(int $orderId): void
    {
        $order = Order::query()
            ->with([
                'items.product.listingImage',
                'items.product.materials',
                'items.product.costHeads',
            ])
            ->findOrFail($orderId);

        $this->cogsModalOpen = true;
        $this->cogsModalOrderId = $order->id;
        $this->cogsModalOrderNumber = (string) $order->order_number;
        $this->cogsModalMessage = null;
        $this->cogsModalRows = [];
        $this->resetValidation();

        foreach ($order->items as $item) {
            $product = $item->product;
            $path = $item->product_image
                ?: $product?->primaryImagePath();
            $thumb = $path
                ? (StorefrontAssets::smallUrl($path) ?? StorefrontAssets::url($path))
                : null;

            $purchase = $product
                ? (float) $product->purchase_price
                : (float) $item->purchase_price;
            $other = $product
                ? (float) $product->costHeads->sum('amount')
                : max(0, (float) $item->effectiveUnitCost() - (float) $item->purchase_price);

            $this->cogsModalRows[] = [
                'key' => 'line-'.$item->id,
                'order_product_id' => $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'name' => $item->displayName(),
                'thumb' => $thumb,
                'qty' => max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0)),
                'purchase_price' => (string) (int) round($purchase),
                'other_cost' => (string) (int) round($other),
                'has_materials' => (bool) $product?->materials->isNotEmpty(),
                'edit_url' => $product ? route('admin.products.edit', $product) : null,
            ];
        }
    }

    public function closeCogsModal(): void
    {
        $this->cogsModalOpen = false;
        $this->cogsModalOrderId = null;
        $this->cogsModalOrderNumber = '';
        $this->cogsModalRows = [];
        $this->cogsModalMessage = null;
        $this->resetValidation();
    }

    public function saveCogsModalRow(int $index, ProductUnitCostService $costs): void
    {
        $row = $this->cogsModalRows[$index] ?? null;

        if (! $row || $this->cogsModalOrderId === null) {
            return;
        }

        $this->validate([
            'cogsModalRows.'.$index.'.purchase_price' => ['required', 'numeric', 'min:0'],
            'cogsModalRows.'.$index.'.other_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = round((float) $this->cogsModalRows[$index]['purchase_price'], 2);
        $other = round((float) $this->cogsModalRows[$index]['other_cost'], 2);

        if ($row['product_id']) {
            $product = Product::query()->with(['materials', 'costHeads'])->findOrFail($row['product_id']);

            if ($product->materials->isNotEmpty() && abs($purchase - (float) $product->purchase_price) > 0.009) {
                $this->addError(
                    'cogsModalRows.'.$index.'.purchase_price',
                    'Main cost comes from BOM materials. Edit the product, or change Other cost only.',
                );

                return;
            }

            $product = $costs->applyPurchaseAndOther($product, $purchase, $other);
            $synced = $costs->syncSnapshotsToOrderProducts($product, $this->cogsModalOrderId);

            $this->cogsModalRows[$index]['purchase_price'] = (string) (int) round((float) $product->purchase_price);
            $this->cogsModalRows[$index]['other_cost'] = (string) (int) round((float) $product->costHeads()->sum('amount'));
            $this->cogsModalMessage = 'Saved “'.$product->name.'” and updated '.$synced.' line(s) on this order.';
        } else {
            OrderProduct::query()->whereKey($row['order_product_id'])->update([
                'purchase_price' => $purchase,
                'unit_cost' => round($purchase + $other, 2),
            ]);

            $this->cogsModalMessage = 'Saved line cost on this order (no linked product).';
        }

        $this->refreshCogsModalRows();
    }

    public function syncCogsModalRowToAllOrders(int $index, ProductUnitCostService $costs): void
    {
        $row = $this->cogsModalRows[$index] ?? null;

        if (! $row || ! $row['product_id']) {
            $this->addError('cogsModalRows.'.$index.'.purchase_price', 'This line has no linked product to sync.');

            return;
        }

        $this->saveCogsModalRow($index, $costs);

        if ($this->getErrorBag()->has('cogsModalRows.'.$index.'.purchase_price')) {
            return;
        }

        $product = Product::query()->findOrFail($row['product_id']);
        $synced = $costs->syncSnapshotsToOrderProducts($product);

        $this->cogsModalMessage = 'Synced “'.$product->name.'” costs to '.$synced.' order line(s) across all orders.';
        $this->refreshCogsModalRows();
    }

    public function render(AnalyticsService $analytics)
    {
        $orders = Order::query()
            ->with(['items', 'courier:id,name,slug,cod_percentage'])
            ->when($this->status === '', fn ($query) => $query->where('status', '!=', Order::STATUS_DRAFT))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('order_number', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->tap(fn (Builder $query) => $this->applyZeroFilters($query))
            ->orderByDesc('id')
            ->paginate(50);

        /** @var array<int, array{revenue: float, cogs: float, packaging: float, courier: float, cod: float, direct: float, profit: float, profit_pct: float|null}> $economicsById */
        $economicsById = [];

        foreach ($orders as $order) {
            $economicsById[$order->id] = $analytics->orderContribution($order);
        }

        return view('livewire.admin.admin-analytics-orders-with-costs', [
            'orders' => $orders,
            'economicsById' => $economicsById,
            'statuses' => [
                '' => 'All statuses',
                'new' => 'New',
                'confirmed' => 'Confirmed',
                'dispatched' => 'Dispatched',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
                'returned' => 'Returned',
                'draft' => 'Draft',
            ],
        ]);
    }

    private function applyZeroFilters(Builder $query): void
    {
        $cogs = $this->cogsExpressionSql();
        $cod = $this->codExpressionSql();
        $packaging = 'COALESCE(orders.packaging_cost, 0)';
        $courier = 'COALESCE(orders.courier_charge, 0)';
        $revenue = 'COALESCE(orders.collected_amount, 0)';
        $direct = "({$cogs} + {$packaging} + {$courier} + {$cod})";
        $profit = "({$revenue} - {$direct})";

        if ($this->zeroRevenue) {
            $query->whereRaw("{$revenue} = 0");
        }

        if ($this->zeroCogs) {
            $query->whereRaw("{$cogs} < 0.01");
        }

        if ($this->zeroPackaging) {
            $query->whereRaw("{$packaging} = 0");
        }

        if ($this->zeroCourier) {
            $query->whereRaw("{$courier} = 0");
        }

        if ($this->zeroCod) {
            $query->whereRaw("{$cod} < 0.01");
        }

        if ($this->zeroDirect) {
            $query->whereRaw("{$direct} < 0.01");
        }

        if ($this->zeroProfit) {
            $query->whereRaw("ABS({$profit}) < 0.01");
        }
    }

    private function cogsExpressionSql(): string
    {
        $qty = $this->greatestSql('(order_products.quantity - COALESCE(order_products.returned_quantity, 0))', '0');

        return "COALESCE((
            SELECT SUM({$qty} * COALESCE(order_products.unit_cost, order_products.purchase_price, 0))
            FROM order_products
            WHERE order_products.order_id = orders.id
        ), 0)";
    }

    private function codExpressionSql(): string
    {
        $steadfastBase = $this->greatestSql(
            '(COALESCE(orders.collected_amount, 0) - COALESCE(orders.delivery_charge, 0))',
            '0',
        );

        return "CASE
            WHEN COALESCE(orders.collected_amount, 0) <= 0 THEN 0
            WHEN COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = orders.courier_id), 1) <= 0 THEN 0
            WHEN LOWER(COALESCE((SELECT couriers.slug FROM couriers WHERE couriers.id = orders.courier_id), '')) = 'steadfast'
                THEN ROUND({$steadfastBase} * COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = orders.courier_id), 1) / 100.0, 2)
            ELSE ROUND(COALESCE(orders.collected_amount, 0) * COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = orders.courier_id), 1) / 100.0, 2)
        END";
    }

    private function greatestSql(string $left, string $right): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "max({$left}, {$right})"
            : "GREATEST({$left}, {$right})";
    }

    private function refreshCogsModalRows(): void
    {
        if ($this->cogsModalOrderId === null) {
            return;
        }

        $orderId = $this->cogsModalOrderId;
        $message = $this->cogsModalMessage;
        $this->openCogsModal($orderId);
        $this->cogsModalMessage = $message;
    }

    private function applyCogsOverride(Order $order, float $targetCogs): void
    {
        $items = $order->items;

        if ($items->isEmpty()) {
            $this->addError('editingValue', 'COGS needs at least one order line.');

            return;
        }

        $currentCogs = $order->cogs();

        if ($currentCogs > 0.009) {
            $factor = $targetCogs / $currentCogs;

            foreach ($items as $item) {
                $item->update([
                    'unit_cost' => round($item->effectiveUnitCost() * $factor, 2),
                ]);
            }

            return;
        }

        /** @var OrderProduct $first */
        $first = $items->first();
        $effectiveQty = max(1, (int) $first->quantity - (int) ($first->returned_quantity ?? 0));

        $first->update([
            'unit_cost' => round($targetCogs / $effectiveQty, 2),
        ]);

        foreach ($items->skip(1) as $item) {
            $item->update(['unit_cost' => 0]);
        }
    }
}
