<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\Admin\AnalyticsService;
use App\Services\Admin\OrderCalculationAuditService;
use App\Services\Admin\OrderSettlementCourierRepairService;
use App\Services\Admin\ProductUnitCostService;
use App\Services\LegacyImport\LegacyDescriptionImporter;
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

    public bool $auditModalOpen = false;

    public bool $auditRunning = false;

    public bool $auditDone = false;

    public int $auditAfterId = 0;

    public int $auditTotal = 0;

    public int $auditScanned = 0;

    public int $auditManualNeeded = 0;

    public int $auditBatchNumber = 0;

    /** All years | specific calendar year (Dhaka). */
    public string $auditYear = '';

    /** @var list<int> */
    public array $auditYearOptions = [];

    /**
     * @var list<array{order_id: int, order_number: string, url: string, issues: list<string>}>
     */
    public array $auditIssues = [];

    public bool $auditIssuesTruncated = false;

    public ?string $auditStatusLine = null;

    public bool $settlementModalOpen = false;

    public bool $settlementRunning = false;

    public bool $settlementDone = false;

    public int $settlementAfterId = 0;

    public int $settlementTotal = 0;

    public int $settlementScanned = 0;

    public int $settlementFixedOrders = 0;

    public int $settlementCourierFixed = 0;

    public int $settlementSettlementFixed = 0;

    public int $settlementPaymentsCreated = 0;

    public int $settlementBatchNumber = 0;

    /** @var list<string> */
    public array $settlementRecentFixes = [];

    public ?string $settlementStatusLine = null;

    public bool $descModalOpen = false;

    public bool $descRunning = false;

    public bool $descDone = false;

    public bool $descForce = false;

    public int $descAfterId = 0;

    public int $descTotal = 0;

    public int $descScanned = 0;

    public int $descUpdated = 0;

    public int $descSkipped = 0;

    public int $descBatchNumber = 0;

    /** @var list<string> */
    public array $descRecentFixes = [];

    public ?string $descStatusLine = null;

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

    public function openCalculationAuditModal(OrderCalculationAuditService $audit): void
    {
        $this->auditModalOpen = true;
        $this->auditRunning = false;
        $this->auditDone = false;
        $this->auditAfterId = 0;
        $this->auditYearOptions = $audit->availableYears();
        $year = $this->auditYearFilter();
        $this->auditTotal = $audit->eligibleOrderCount($year);
        $this->auditScanned = 0;
        $this->auditManualNeeded = 0;
        $this->auditBatchNumber = 0;
        $this->auditIssues = [];
        $this->auditIssuesTruncated = false;
        $this->auditStatusLine = $this->auditReadyMessage();
    }

    public function updatedAuditYear(OrderCalculationAuditService $audit): void
    {
        if (! $this->auditModalOpen || $this->auditRunning) {
            return;
        }

        $this->auditDone = false;
        $this->auditAfterId = 0;
        $this->auditScanned = 0;
        $this->auditManualNeeded = 0;
        $this->auditBatchNumber = 0;
        $this->auditIssues = [];
        $this->auditIssuesTruncated = false;
        $this->auditTotal = $audit->eligibleOrderCount($this->auditYearFilter());
        $this->auditStatusLine = $this->auditReadyMessage();
    }

    public function startCalculationAudit(OrderCalculationAuditService $audit): void
    {
        if (! $this->auditModalOpen || $this->auditRunning) {
            return;
        }

        if ($this->auditTotal < 1) {
            $this->auditDone = true;
            $this->auditStatusLine = 'No orders to audit for this scope.';

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
            $this->auditYearFilter(),
        );

        $this->auditBatchNumber++;
        $this->auditAfterId = $result['next_after_id'];
        $this->auditScanned += $result['scanned'];
        $this->auditManualNeeded += $result['manual_needed'];

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
            .' · issues '.number_format($this->auditManualNeeded);

        if ($result['done']) {
            $this->auditRunning = false;
            $this->auditDone = true;
            $this->auditStatusLine = 'Done · scanned '.number_format($this->auditScanned)
                .' · issues '.number_format($this->auditManualNeeded)
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
            .' · issues '.number_format($this->auditManualNeeded).' so far. Resume to continue.';
    }

    public function closeCalculationAuditModal(): void
    {
        $this->auditRunning = false;
        $this->auditModalOpen = false;
    }

    private function auditYearFilter(): ?int
    {
        if ($this->auditYear === '' || ! ctype_digit($this->auditYear)) {
            return null;
        }

        return (int) $this->auditYear;
    }

    private function auditReadyMessage(): string
    {
        if ($this->auditTotal < 1) {
            return 'No orders to audit for this scope.';
        }

        $scope = $this->auditYearFilter() === null
            ? 'all years'
            : 'year '.$this->auditYearFilter();

        return 'Ready to check column integrity for '.number_format($this->auditTotal)
            .' orders ('.$scope.') in batches of '.OrderCalculationAuditService::BATCH_SIZE
            .'. Report only — nothing is auto-changed.';
    }

    public function openLegacyDescriptionModal(LegacyDescriptionImporter $importer): void
    {
        $this->descModalOpen = true;
        $this->descRunning = false;
        $this->descDone = false;
        $this->descAfterId = 0;
        $this->descScanned = 0;
        $this->descUpdated = 0;
        $this->descSkipped = 0;
        $this->descBatchNumber = 0;
        $this->descRecentFixes = [];

        try {
            $importer->assertLegacyConnection();
            $this->descTotal = $importer->eligibleLegacyCount();
            $this->descStatusLine = $this->descTotal === 0
                ? 'No legacy product descriptions found to copy.'
                : 'Ready to copy descriptions for '.number_format($this->descTotal)
                    .' legacy products in batches of '.LegacyDescriptionImporter::BATCH_SIZE
                    .'. Empty sun2 fields are filled; enable overwrite to replace existing text.';
        } catch (\Throwable $e) {
            // Keep descDone false so the Start button stays visible for retry.
            $this->descTotal = 0;
            $this->descDone = false;
            $this->descStatusLine = 'Legacy DB unavailable: '.$e->getMessage();
        }
    }

    public function startLegacyDescriptionImport(LegacyDescriptionImporter $importer): void
    {
        if (! $this->descModalOpen || $this->descRunning) {
            return;
        }

        try {
            $importer->assertLegacyConnection();
            if ($this->descTotal < 1) {
                $this->descTotal = $importer->eligibleLegacyCount();
            }
        } catch (\Throwable $e) {
            $this->descRunning = false;
            $this->descDone = false;
            $this->descStatusLine = 'Legacy DB unavailable: '.$e->getMessage();

            return;
        }

        if ($this->descTotal < 1) {
            $this->descDone = true;
            $this->descStatusLine = 'No legacy product descriptions found to copy.';

            return;
        }

        $this->descRunning = true;
        $this->descDone = false;
        $this->descStatusLine = 'Starting…';
        $this->continueLegacyDescriptionImport($importer);
    }

    public function continueLegacyDescriptionImport(LegacyDescriptionImporter $importer): void
    {
        if (! $this->descModalOpen || ! $this->descRunning || $this->descDone) {
            return;
        }

        try {
            $result = $importer->importNextBatch(
                $this->descAfterId,
                LegacyDescriptionImporter::BATCH_SIZE,
                $this->descForce,
            );
        } catch (\Throwable $e) {
            $this->descRunning = false;
            $this->descDone = true;
            $this->descStatusLine = 'Stopped — '.$e->getMessage();

            return;
        }

        $this->descBatchNumber++;
        $this->descAfterId = $result['next_after_id'];
        $this->descScanned += $result['scanned'];
        $this->descUpdated += $result['updated'];
        $this->descSkipped += $result['skipped'];

        foreach ($result['sample_names'] as $name) {
            if (! in_array($name, $this->descRecentFixes, true)) {
                $this->descRecentFixes[] = $name;
            }
        }
        $this->descRecentFixes = array_slice($this->descRecentFixes, -12);

        $pct = $this->descTotal > 0
            ? min(100, (int) round(($this->descScanned / $this->descTotal) * 100))
            : 100;

        $this->descStatusLine = 'Batch '.$this->descBatchNumber
            .' · scanned '.number_format($this->descScanned).' / '.number_format($this->descTotal)
            .' ('.$pct.'%)'
            .' · updated '.number_format($this->descUpdated)
            .' · skipped '.number_format($this->descSkipped);

        if ($result['done'] || $result['scanned'] === 0) {
            $this->descRunning = false;
            $this->descDone = true;
            $this->descStatusLine = 'Done · scanned '.number_format($this->descScanned)
                .' · updated '.number_format($this->descUpdated)
                .' · skipped '.number_format($this->descSkipped)
                .($this->descForce ? ' (overwrite on)' : '');
        }
    }

    public function stopLegacyDescriptionImport(): void
    {
        if (! $this->descRunning) {
            return;
        }

        $this->descRunning = false;
        $this->descStatusLine = 'Paused at '.number_format($this->descScanned)
            .' / '.number_format($this->descTotal)
            .' · updated '.number_format($this->descUpdated).' so far. Resume to continue.';
    }

    public function closeLegacyDescriptionModal(): void
    {
        $this->descRunning = false;
        $this->descModalOpen = false;
    }

    public function openSettlementRepairModal(OrderSettlementCourierRepairService $repair): void
    {
        $this->settlementModalOpen = true;
        $this->settlementRunning = false;
        $this->settlementDone = false;
        $this->settlementAfterId = 0;
        $this->settlementTotal = $repair->eligibleOrderCount();
        $this->settlementScanned = 0;
        $this->settlementFixedOrders = 0;
        $this->settlementCourierFixed = 0;
        $this->settlementSettlementFixed = 0;
        $this->settlementPaymentsCreated = 0;
        $this->settlementBatchNumber = 0;
        $this->settlementRecentFixes = [];
        $this->settlementStatusLine = $this->settlementTotal === 0
            ? 'No orders need settlement or courier repair.'
            : 'Ready to repair '.number_format($this->settlementTotal)
                .' orders in batches of '.OrderSettlementCourierRepairService::BATCH_SIZE
                .' (৳0 courier on delivered/returned + unpaid delivered bills).';
    }

    public function startSettlementRepair(OrderSettlementCourierRepairService $repair): void
    {
        if (! $this->settlementModalOpen || $this->settlementRunning) {
            return;
        }

        if ($this->settlementTotal < 1) {
            $this->settlementDone = true;
            $this->settlementStatusLine = 'No orders need settlement or courier repair.';

            return;
        }

        $this->settlementRunning = true;
        $this->settlementDone = false;
        $this->settlementStatusLine = 'Starting…';
        $this->continueSettlementRepair($repair);
    }

    public function continueSettlementRepair(OrderSettlementCourierRepairService $repair): void
    {
        if (! $this->settlementModalOpen || ! $this->settlementRunning || $this->settlementDone) {
            return;
        }

        $result = $repair->repairNextBatch(
            $this->settlementAfterId,
            OrderSettlementCourierRepairService::BATCH_SIZE,
        );

        $this->settlementBatchNumber++;
        $this->settlementAfterId = $result['next_after_id'];
        $this->settlementScanned += $result['scanned'];
        $this->settlementFixedOrders += $result['fixed_orders'];
        $this->settlementCourierFixed += $result['courier_fixed'];
        $this->settlementSettlementFixed += $result['settlement_fixed'];
        $this->settlementPaymentsCreated += $result['payments_created'];

        foreach ($result['sample_order_numbers'] as $orderNumber) {
            if (! in_array($orderNumber, $this->settlementRecentFixes, true)) {
                $this->settlementRecentFixes[] = $orderNumber;
            }
        }
        $this->settlementRecentFixes = array_slice($this->settlementRecentFixes, -12);

        $pct = $this->settlementTotal > 0
            ? min(100, (int) round(($this->settlementScanned / $this->settlementTotal) * 100))
            : 100;

        $this->settlementStatusLine = 'Batch '.$this->settlementBatchNumber
            .' · scanned '.number_format($this->settlementScanned).' / '.number_format($this->settlementTotal)
            .' ('.$pct.'%)'
            .' · fixed '.number_format($this->settlementFixedOrders)
            .' · courier '.number_format($this->settlementCourierFixed)
            .' · settled '.number_format($this->settlementSettlementFixed);

        if ($result['done'] || $result['scanned'] === 0) {
            $this->settlementRunning = false;
            $this->settlementDone = true;
            $this->settlementStatusLine = 'Done · scanned '.number_format($this->settlementScanned)
                .' · fixed '.number_format($this->settlementFixedOrders).' orders'
                .' · courier filled '.number_format($this->settlementCourierFixed)
                .' · settlements '.number_format($this->settlementSettlementFixed)
                .' · payments created '.number_format($this->settlementPaymentsCreated);
        }
    }

    public function stopSettlementRepair(): void
    {
        if (! $this->settlementRunning) {
            return;
        }

        $this->settlementRunning = false;
        $this->settlementStatusLine = 'Paused at '.number_format($this->settlementScanned)
            .' / '.number_format($this->settlementTotal)
            .' · fixed '.number_format($this->settlementFixedOrders).' so far. Resume to continue.';
    }

    public function closeSettlementRepairModal(): void
    {
        $this->settlementRunning = false;
        $this->settlementModalOpen = false;
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
