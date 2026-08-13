<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\ProductUnitCostService;
use App\Support\AdminAccess;
use App\Support\OrderEconomicsSql;
use App\Support\StorefrontAssets;
use Illuminate\Database\Eloquent\Builder;
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
            // Orphan product_id (deleted catalog row) — treat as unlinked for cost edits.
            $productId = $product ? (int) $item->product_id : null;
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
                'product_id' => $productId,
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
        $result = $this->persistCogsModalRow($index, $costs, syncAllOrders: false);

        if ($result === null) {
            return;
        }

        if ($result['product'] !== null) {
            $this->cogsModalMessage = 'Saved “'.$result['product']->name.'” and updated '.$result['synced'].' line(s) on this order.';
        } else {
            $this->cogsModalMessage = 'Saved line cost on this order (no linked product).';
        }

        $this->afterCogsModalCostWrite();
    }

    public function syncCogsModalRowToAllOrders(int $index, ProductUnitCostService $costs): void
    {
        $row = $this->cogsModalRows[$index] ?? null;

        if (! $row || ! $row['product_id']) {
            $this->addError('cogsModalRows.'.$index.'.purchase_price', 'This line has no linked product to sync.');

            return;
        }

        $result = $this->persistCogsModalRow($index, $costs, syncAllOrders: true);

        if ($result === null || $result['product'] === null) {
            return;
        }

        $message = 'Synced “'.$result['product']->name.'” costs to '.$result['synced']
            .' open order line(s) (cancelled/returned skipped).';

        $nextId = $this->nextOrderIdForCogsModal();

        if ($nextId === null) {
            $this->closeCogsModal();

            return;
        }

        if ($nextId === $this->cogsModalOrderId) {
            $this->cogsModalMessage = $message;
            $this->refreshCogsModalRows();

            return;
        }

        $this->openCogsModal($nextId);
        $this->cogsModalMessage = $message.' Now editing '.$this->cogsModalOrderNumber.'.';
    }

    public function render(AnalyticsService $analytics)
    {
        $orders = $this->filteredOrdersQuery()
            ->with(['items', 'courier:id,name,slug,cod_percentage'])
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
                'paid' => 'Paid (legacy)',
                'cancelled' => 'Cancelled',
                'returned' => 'Returned',
                'draft' => 'Draft',
            ],
        ]);
    }

    private function filteredOrdersQuery(): Builder
    {
        return Order::query()
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
            ->tap(fn (Builder $query) => $this->applyZeroFilters($query));
    }

    /**
     * Next order to edit in the Fix product costs modal after a sync.
     * Stays on the current order when any kept line still has ৳0 COGS;
     * otherwise moves to the next order in the current table filters.
     */
    private function nextOrderIdForCogsModal(): ?int
    {
        $currentId = $this->cogsModalOrderId;

        if ($currentId === null) {
            return null;
        }

        $current = Order::query()->with('items')->find($currentId);

        if ($current && $this->orderStillNeedsProductCostFix($current)) {
            return $currentId;
        }

        $base = $this->filteredOrdersQuery();

        $next = (clone $base)
            ->where('id', '<', $currentId)
            ->orderByDesc('id')
            ->value('id');

        if ($next !== null) {
            return (int) $next;
        }

        $wrapped = (clone $base)
            ->where('id', '!=', $currentId)
            ->orderByDesc('id')
            ->value('id');

        return $wrapped !== null ? (int) $wrapped : null;
    }

    private function orderStillNeedsProductCostFix(Order $order): bool
    {
        $status = strtolower((string) $order->status);

        if (in_array($status, ['cancelled', 'returned', Order::STATUS_DRAFT], true)) {
            return false;
        }

        foreach ($order->items as $item) {
            $kept = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));

            if ($kept < 1) {
                continue;
            }

            if ($item->effectiveUnitCost() < 0.01) {
                return true;
            }
        }

        return false;
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
        return OrderEconomicsSql::cogsExpression();
    }

    private function codExpressionSql(): string
    {
        return OrderEconomicsSql::codExpression();
    }

    /**
     * Validate and persist modal row costs onto the product and/or order line snapshots.
     *
     * @return array{product: Product|null, synced: int}|null Null when validation blocked the write
     */
    private function persistCogsModalRow(int $index, ProductUnitCostService $costs, bool $syncAllOrders): ?array
    {
        $row = $this->cogsModalRows[$index] ?? null;

        if (! $row || $this->cogsModalOrderId === null) {
            return null;
        }

        $this->validate([
            'cogsModalRows.'.$index.'.purchase_price' => ['required', 'numeric', 'min:0'],
            'cogsModalRows.'.$index.'.other_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = round((float) $this->cogsModalRows[$index]['purchase_price'], 2);
        $other = round((float) $this->cogsModalRows[$index]['other_cost'], 2);
        $unitCost = round($purchase + $other, 2);

        $product = null;
        if ($row['product_id']) {
            $product = Product::query()->with(['materials', 'costHeads'])->find($row['product_id']);
        }

        // No catalog row (never linked, or product deleted) — update this order line only.
        if ($product === null) {
            OrderProduct::query()->whereKey($row['order_product_id'])->update([
                'purchase_price' => $purchase,
                'unit_cost' => $unitCost,
                // Clear orphaned FK so later saves stay on the line-only path.
                'product_id' => null,
            ]);

            $this->cogsModalRows[$index]['product_id'] = null;
            $this->cogsModalRows[$index]['edit_url'] = null;
            $this->cogsModalRows[$index]['has_materials'] = false;
            $this->cogsModalRows[$index]['purchase_price'] = (string) (int) round($purchase);
            $this->cogsModalRows[$index]['other_cost'] = (string) (int) round($other);

            return [
                'product' => null,
                'synced' => 1,
            ];
        }

        if ($product->materials->isNotEmpty() && abs($purchase - (float) $product->purchase_price) > 0.009) {
            $this->addError(
                'cogsModalRows.'.$index.'.purchase_price',
                'Main cost comes from BOM materials. Edit the product, or change Other cost only.',
            );

            return null;
        }

        $product = $costs->applyPurchaseAndOther($product, $purchase, $other);

        $synced = $syncAllOrders
            ? $costs->syncSnapshotsToOrderProducts($product)
            : $costs->syncSnapshotsToOrderProducts($product, $this->cogsModalOrderId);

        $this->cogsModalRows[$index]['purchase_price'] = (string) (int) round((float) $product->purchase_price);
        $this->cogsModalRows[$index]['other_cost'] = (string) (int) round((float) $product->costHeads()->sum('amount'));

        return [
            'product' => $product,
            'synced' => $synced,
        ];
    }

    private function afterCogsModalCostWrite(): void
    {
        $this->refreshCogsModalRows();
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
