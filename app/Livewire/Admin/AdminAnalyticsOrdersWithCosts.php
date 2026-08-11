<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderCalculationAuditService;
use App\Services\Admin\OrderCostSnapshotRepairService;
use App\Services\Admin\OrderPackagingCourierRepairService;
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

    public bool $repairModalOpen = false;

    public bool $repairRunning = false;

    public bool $repairDone = false;

    public int $repairAfterId = 0;

    public int $repairTotal = 0;

    public int $repairScanned = 0;

    public int $repairFixedOrders = 0;

    public int $repairBackfilledLines = 0;

    public int $repairClearedReturnLines = 0;

    public int $repairBatchNumber = 0;

    /** @var list<string> */
    public array $repairRecentFixes = [];

    public ?string $repairStatusLine = null;

    public bool $logisticsRepairModalOpen = false;

    public bool $logisticsRepairRunning = false;

    public bool $logisticsRepairDone = false;

    public int $logisticsRepairAfterId = 0;

    public int $logisticsRepairTotal = 0;

    public int $logisticsRepairScanned = 0;

    public int $logisticsRepairFixedOrders = 0;

    public int $logisticsRepairPackagingFixed = 0;

    public int $logisticsRepairCourierFixed = 0;

    public int $logisticsRepairBatchNumber = 0;

    /** @var list<string> */
    public array $logisticsRepairRecentFixes = [];

    public ?string $logisticsRepairStatusLine = null;

    public bool $auditModalOpen = false;

    public bool $auditRunning = false;

    public bool $auditDone = false;

    public int $auditAfterId = 0;

    public int $auditTotal = 0;

    public int $auditScanned = 0;

    public int $auditAutoFixed = 0;

    public int $auditManualNeeded = 0;

    public int $auditBatchNumber = 0;

    /** @var list<string> */
    public array $auditRecentFixes = [];

    /**
     * @var list<array{order_id: int, order_number: string, url: string, issues: list<string>}>
     */
    public array $auditIssues = [];

    public bool $auditIssuesTruncated = false;

    public ?string $auditStatusLine = null;

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

    public function openCostRepairModal(OrderCostSnapshotRepairService $repair): void
    {
        $this->repairModalOpen = true;
        $this->repairRunning = false;
        $this->repairDone = false;
        $this->repairAfterId = 0;
        $this->repairTotal = $repair->eligibleOrderCount();
        $this->repairScanned = 0;
        $this->repairFixedOrders = 0;
        $this->repairBackfilledLines = 0;
        $this->repairClearedReturnLines = 0;
        $this->repairBatchNumber = 0;
        $this->repairRecentFixes = [];
        $this->repairStatusLine = $this->repairTotal === 0
            ? 'No orders to scan.'
            : 'Ready to scan '.number_format($this->repairTotal).' orders in batches of '.OrderCostSnapshotRepairService::BATCH_SIZE.'.';
    }

    public function startCostRepair(OrderCostSnapshotRepairService $repair): void
    {
        if (! $this->repairModalOpen || $this->repairRunning) {
            return;
        }

        if ($this->repairTotal < 1) {
            $this->repairDone = true;
            $this->repairStatusLine = 'No orders to scan.';

            return;
        }

        $this->repairRunning = true;
        $this->repairDone = false;
        $this->repairStatusLine = 'Starting…';
        $this->continueCostRepair($repair);
    }

    public function continueCostRepair(OrderCostSnapshotRepairService $repair): void
    {
        if (! $this->repairModalOpen || ! $this->repairRunning || $this->repairDone) {
            return;
        }

        $result = $repair->repairNextBatch(
            $this->repairAfterId,
            OrderCostSnapshotRepairService::BATCH_SIZE,
        );

        $this->repairBatchNumber++;
        $this->repairAfterId = $result['next_after_id'];
        $this->repairScanned += $result['scanned'];
        $this->repairFixedOrders += $result['fixed_orders'];
        $this->repairBackfilledLines += $result['backfilled_lines'];
        $this->repairClearedReturnLines += $result['cleared_return_lines'];

        foreach ($result['sample_order_numbers'] as $orderNumber) {
            if (! in_array($orderNumber, $this->repairRecentFixes, true)) {
                $this->repairRecentFixes[] = $orderNumber;
            }
        }
        $this->repairRecentFixes = array_slice($this->repairRecentFixes, -12);

        $pct = $this->repairTotal > 0
            ? min(100, (int) round(($this->repairScanned / $this->repairTotal) * 100))
            : 100;

        $this->repairStatusLine = 'Batch '.$this->repairBatchNumber
            .' · scanned '.number_format($this->repairScanned).' / '.number_format($this->repairTotal)
            .' ('.$pct.'%)'
            .' · fixed '.number_format($this->repairFixedOrders).' orders'
            .' · backfilled '.number_format($this->repairBackfilledLines)
            .' · cleared '.number_format($this->repairClearedReturnLines).' return lines';

        if ($result['done'] || $result['scanned'] === 0) {
            $this->repairRunning = false;
            $this->repairDone = true;
            $this->repairStatusLine = 'Done · scanned '.number_format($this->repairScanned)
                .' · fixed '.number_format($this->repairFixedOrders).' orders'
                .' · backfilled '.number_format($this->repairBackfilledLines).' lines'
                .' · cleared '.number_format($this->repairClearedReturnLines).' return lines';
        }
    }

    public function stopCostRepair(): void
    {
        if (! $this->repairRunning) {
            return;
        }

        $this->repairRunning = false;
        $this->repairStatusLine = 'Paused at '.number_format($this->repairScanned)
            .' / '.number_format($this->repairTotal)
            .' · fixed '.number_format($this->repairFixedOrders).' so far. Resume to continue.';
    }

    public function closeCostRepairModal(): void
    {
        $this->repairRunning = false;
        $this->repairModalOpen = false;
    }

    public function openLogisticsRepairModal(OrderPackagingCourierRepairService $repair): void
    {
        $this->logisticsRepairModalOpen = true;
        $this->logisticsRepairRunning = false;
        $this->logisticsRepairDone = false;
        $this->logisticsRepairAfterId = 0;
        $this->logisticsRepairTotal = $repair->eligibleOrderCount();
        $this->logisticsRepairScanned = 0;
        $this->logisticsRepairFixedOrders = 0;
        $this->logisticsRepairPackagingFixed = 0;
        $this->logisticsRepairCourierFixed = 0;
        $this->logisticsRepairBatchNumber = 0;
        $this->logisticsRepairRecentFixes = [];
        $this->logisticsRepairStatusLine = $this->logisticsRepairTotal === 0
            ? 'No orders with ৳0 packaging or courier to repair.'
            : 'Ready to repair '.number_format($this->logisticsRepairTotal).' orders (৳0 packaging and/or courier) in batches of '.OrderPackagingCourierRepairService::BATCH_SIZE.'.';
    }

    public function startLogisticsRepair(OrderPackagingCourierRepairService $repair): void
    {
        if (! $this->logisticsRepairModalOpen || $this->logisticsRepairRunning) {
            return;
        }

        if ($this->logisticsRepairTotal < 1) {
            $this->logisticsRepairDone = true;
            $this->logisticsRepairStatusLine = 'No orders with ৳0 packaging or courier to repair.';

            return;
        }

        $this->logisticsRepairRunning = true;
        $this->logisticsRepairDone = false;
        $this->logisticsRepairStatusLine = 'Starting…';
        $this->continueLogisticsRepair($repair);
    }

    public function continueLogisticsRepair(OrderPackagingCourierRepairService $repair): void
    {
        if (! $this->logisticsRepairModalOpen || ! $this->logisticsRepairRunning || $this->logisticsRepairDone) {
            return;
        }

        $result = $repair->repairNextBatch(
            $this->logisticsRepairAfterId,
            OrderPackagingCourierRepairService::BATCH_SIZE,
        );

        $this->logisticsRepairBatchNumber++;
        $this->logisticsRepairAfterId = $result['next_after_id'];
        $this->logisticsRepairScanned += $result['scanned'];
        $this->logisticsRepairFixedOrders += $result['fixed_orders'];
        $this->logisticsRepairPackagingFixed += $result['packaging_fixed'];
        $this->logisticsRepairCourierFixed += $result['courier_fixed'];

        foreach ($result['sample_order_numbers'] as $orderNumber) {
            if (! in_array($orderNumber, $this->logisticsRepairRecentFixes, true)) {
                $this->logisticsRepairRecentFixes[] = $orderNumber;
            }
        }
        $this->logisticsRepairRecentFixes = array_slice($this->logisticsRepairRecentFixes, -12);

        $pct = $this->logisticsRepairTotal > 0
            ? min(100, (int) round(($this->logisticsRepairScanned / $this->logisticsRepairTotal) * 100))
            : 100;

        $this->logisticsRepairStatusLine = 'Batch '.$this->logisticsRepairBatchNumber
            .' · scanned '.number_format($this->logisticsRepairScanned).' / '.number_format($this->logisticsRepairTotal)
            .' ('.$pct.'%)'
            .' · fixed '.number_format($this->logisticsRepairFixedOrders).' orders'
            .' · packaging '.number_format($this->logisticsRepairPackagingFixed)
            .' · courier '.number_format($this->logisticsRepairCourierFixed);

        if ($result['done'] || $result['scanned'] === 0) {
            $this->logisticsRepairRunning = false;
            $this->logisticsRepairDone = true;
            $this->logisticsRepairStatusLine = 'Done · scanned '.number_format($this->logisticsRepairScanned)
                .' · fixed '.number_format($this->logisticsRepairFixedOrders).' orders'
                .' · packaging '.number_format($this->logisticsRepairPackagingFixed)
                .' · courier '.number_format($this->logisticsRepairCourierFixed);
        }
    }

    public function stopLogisticsRepair(): void
    {
        if (! $this->logisticsRepairRunning) {
            return;
        }

        $this->logisticsRepairRunning = false;
        $this->logisticsRepairStatusLine = 'Paused at '.number_format($this->logisticsRepairScanned)
            .' / '.number_format($this->logisticsRepairTotal)
            .' · fixed '.number_format($this->logisticsRepairFixedOrders).' so far. Resume to continue.';
    }

    public function closeLogisticsRepairModal(): void
    {
        $this->logisticsRepairRunning = false;
        $this->logisticsRepairModalOpen = false;
    }

    public function openCalculationAuditModal(OrderCalculationAuditService $audit): void
    {
        $this->auditModalOpen = true;
        $this->auditRunning = false;
        $this->auditDone = false;
        $this->auditAfterId = 0;
        $this->auditTotal = $audit->eligibleOrderCount();
        $this->auditScanned = 0;
        $this->auditAutoFixed = 0;
        $this->auditManualNeeded = 0;
        $this->auditBatchNumber = 0;
        $this->auditRecentFixes = [];
        $this->auditIssues = [];
        $this->auditIssuesTruncated = false;
        $this->auditStatusLine = $this->auditTotal === 0
            ? 'No non-draft orders to audit.'
            : 'Ready to audit '.number_format($this->auditTotal).' orders against current cost/payment rules in batches of '.OrderCalculationAuditService::BATCH_SIZE.'.';
    }

    public function startCalculationAudit(OrderCalculationAuditService $audit): void
    {
        if (! $this->auditModalOpen || $this->auditRunning) {
            return;
        }

        if ($this->auditTotal < 1) {
            $this->auditDone = true;
            $this->auditStatusLine = 'No non-draft orders to audit.';

            return;
        }

        $this->auditRunning = true;
        $this->auditDone = false;
        $this->auditStatusLine = 'Starting…';
        $this->continueCalculationAudit($audit);
    }

    public function continueCalculationAudit(OrderCalculationAuditService $audit): void
    {
        if (! $this->auditModalOpen || ! $this->auditRunning || $this->auditDone) {
            return;
        }

        $result = $audit->auditNextBatch(
            $this->auditAfterId,
            OrderCalculationAuditService::BATCH_SIZE,
        );

        $this->auditBatchNumber++;
        $this->auditAfterId = $result['next_after_id'];
        $this->auditScanned += $result['scanned'];
        $this->auditAutoFixed += $result['auto_fixed'];
        $this->auditManualNeeded += $result['manual_needed'];

        foreach ($result['sample_auto_fixes'] as $orderNumber) {
            if (! in_array($orderNumber, $this->auditRecentFixes, true)) {
                $this->auditRecentFixes[] = $orderNumber;
            }
        }
        $this->auditRecentFixes = array_slice($this->auditRecentFixes, -12);

        foreach ($result['issues'] as $issue) {
            if (count($this->auditIssues) >= OrderCalculationAuditService::MAX_STORED_ISSUES) {
                $this->auditIssuesTruncated = true;
                break;
            }
            $this->auditIssues[] = $issue;
        }

        $pct = $this->auditTotal > 0
            ? min(100, (int) round(($this->auditScanned / $this->auditTotal) * 100))
            : 100;

        $this->auditStatusLine = 'Batch '.$this->auditBatchNumber
            .' · scanned '.number_format($this->auditScanned).' / '.number_format($this->auditTotal)
            .' ('.$pct.'%)'
            .' · auto-fixed '.number_format($this->auditAutoFixed)
            .' · manual '.number_format($this->auditManualNeeded);

        if ($result['done']) {
            $this->auditRunning = false;
            $this->auditDone = true;
            $this->auditStatusLine = 'Done · scanned '.number_format($this->auditScanned)
                .' · auto-fixed '.number_format($this->auditAutoFixed)
                .' · need manual review '.number_format($this->auditManualNeeded)
                .($this->auditIssuesTruncated
                    ? ' (showing first '.number_format(count($this->auditIssues)).' issue rows)'
                    : '');
        }
    }

    public function stopCalculationAudit(): void
    {
        if (! $this->auditRunning) {
            return;
        }

        $this->auditRunning = false;
        $this->auditStatusLine = 'Paused at '.number_format($this->auditScanned)
            .' / '.number_format($this->auditTotal)
            .' · auto-fixed '.number_format($this->auditAutoFixed)
            .' · manual '.number_format($this->auditManualNeeded).' so far. Resume to continue.';
    }

    public function closeCalculationAuditModal(): void
    {
        $this->auditRunning = false;
        $this->auditModalOpen = false;
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

        if (! $row['product_id']) {
            OrderProduct::query()->whereKey($row['order_product_id'])->update([
                'purchase_price' => $purchase,
                'unit_cost' => round($purchase + $other, 2),
            ]);

            return [
                'product' => null,
                'synced' => 1,
            ];
        }

        $product = Product::query()->with(['materials', 'costHeads'])->findOrFail($row['product_id']);

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
