<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Admin\AdminOrderService;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\OrderDispatchService;
use App\Services\Admin\ProductShareListService;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\CourierTrackingService;
use App\Support\AdminAccess;
use App\Support\AdminOrderSegment;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AdminOrders extends Component
{
    use ManagesProductImagePreview;
    use WithPagination;

    public string $segment = 'new';

    #[Url]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $showSendToModal = false;

    public string $sendToCourierSlug = '';

    public bool $showDispatchModal = false;

    public ?int $dispatchCourierId = null;

    public bool $showBulkSendProgress = false;

    public bool $bulkSending = false;

    /**
     * @var array<int, array{order_id:int,order_number:string,customer:string,status:string,message:?string,tracker:?string}>
     */
    public array $bulkSendRows = [];

    /**
     * Live / stored courier delivery status keyed by order id.
     *
     * @var array<int, string|null>
     */
    public array $courierLiveStatuses = [];

    public int $listRevision = 0;

    public bool $showPartialModal = false;

    public ?int $partialOrderId = null;

    public string $partialOrderNumber = '';

    /** @var array<int|string, int|string> */
    public array $partialReturns = [];

    public string $partialCollectedTk = '0';

    /** @var list<array{id:int,name:string,quantity:int,image:?string}> */
    public array $partialItems = [];

    public function mount(string $segment = 'new'): void
    {
        $this->segment = AdminOrderSegment::isValid($segment) ? $segment : 'new';

        if (AdminAccess::isModeratorOnly() && $this->segment !== 'new') {
            $this->redirect(route('admin.orders.new'), navigate: true);

            return;
        }

        $courierRegistry = app(CourierApiRegistry::class);

        $apiCouriers = Courier::query()
            ->where('is_active', true)
            ->whereIn('slug', $courierRegistry->configuredSlugs())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $this->sendToCourierSlug = (string) (
            $apiCouriers->firstWhere('is_default', true)?->slug
            ?? $apiCouriers->firstWhere('slug', 'steadfast')?->slug
            ?? $apiCouriers->first()?->slug
            ?? ''
        );

        $this->dispatchCourierId = Courier::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->value('id');

        // Defer live API calls so the list paints first, then tracking fills in.
        $this->queueCourierStatusRefresh();
    }

    public function switchSegment(string $segment): void
    {
        if (AdminAccess::isModeratorOnly()) {
            return;
        }

        if (! AdminOrderSegment::isValid($segment) || $this->segment === $segment) {
            return;
        }

        $this->segment = $segment;
        $this->resetPage();
        $this->selected = [];
        $this->closeSendModals();
        $this->courierLiveStatuses = [];

        $this->js('history.replaceState({}, "", '.json_encode(route('admin.orders.'.$segment)).')');
        $this->queueCourierStatusRefresh();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->courierLiveStatuses = [];
        $this->queueCourierStatusRefresh();
    }

    public function toggleOrder(int $orderId): void
    {
        if (AdminAccess::isModeratorOnly()) {
            return;
        }

        $orderId = (int) $orderId;

        if (in_array($orderId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$orderId]));
        } else {
            $this->selected = [...$this->selected, $orderId];
        }
    }

    public function togglePageSelection(): void
    {
        if (AdminAccess::isModeratorOnly()) {
            return;
        }

        $pageIds = $this->pageOrderIds();

        if ($pageIds === []) {
            return;
        }

        $selectedOnPage = array_values(array_intersect($this->selected, $pageIds));
        $allSelected = count($selectedOnPage) === count($pageIds);

        if ($allSelected) {
            $this->selected = array_values(array_diff($this->selected, $pageIds));
        } else {
            $this->selected = array_values(array_unique(array_merge($this->selected, $pageIds)));
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function listSelectedProducts(ProductShareListService $shares): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return;
        }

        $share = $shares->createFromOrders($this->selected, auth()->id());
        $url = route('share.products', $share->token);

        $this->js('window.open('.json_encode($url).', "_blank")');
    }

    public function deleteOrder(int $orderId, AdminOrderService $orders): void
    {
        AdminAccess::ensureStaffAdmin();

        $order = Order::query()->find($orderId);

        if (! $order) {
            return;
        }

        $orders->delete($order);
        $this->selected = array_values(array_diff($this->selected, [(int) $orderId]));
    }

    public function deleteSelected(AdminOrderService $orders): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return;
        }

        $toDelete = Order::query()
            ->whereIn('id', $this->selected)
            ->get();

        foreach ($toDelete as $order) {
            $orders->delete($order);
        }

        $this->selected = [];
        $this->closeSendModals();
    }

    public function quickDispatch(int $orderId, OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'new') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order || ! in_array($order->status, ['new', 'confirmed'], true)) {
            return;
        }

        $courierId = Courier::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->value('id');

        if (! $courierId) {
            return;
        }

        $dispatch->markAsDispatched($order, (int) $courierId);
        $this->selected = array_values(array_diff($this->selected, [(int) $orderId]));
    }

    public function markDelivered(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order || $order->status !== 'dispatched') {
            return;
        }

        $settlement->markDelivered($order);
        $this->removeSettledFromList([(int) $orderId]);
    }

    public function markSelectedDelivered(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched' || $this->selected === []) {
            return;
        }

        $orders = Order::query()
            ->whereIn('id', $this->selected)
            ->where('status', 'dispatched')
            ->get();

        $settledIds = [];

        foreach ($orders as $order) {
            $settlement->markDelivered($order);
            $settledIds[] = (int) $order->id;
        }

        $this->removeSettledFromList($settledIds);
    }

    public function cancelAndReturn(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order || $order->status !== 'dispatched') {
            return;
        }

        $settlement->cancelAndReturn($order);
        $this->removeSettledFromList([(int) $orderId]);
    }

    public function markReturnReceived(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'return-pending') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order || ! $order->has_return) {
            return;
        }

        $settlement->markReturnReceived($order);
    }

    public function undoReturnReceived(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'return-pending') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order) {
            return;
        }

        $settlement->undoReturnReceived($order);
    }

    public function toggleHasReturn(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'return-pending') {
            return;
        }

        $order = Order::query()->find($orderId);

        if (! $order) {
            return;
        }

        $next = ! (bool) $order->has_return;
        $settlement->setHasReturn($order, $next);

        if (! $next) {
            $this->removeSettledFromList([(int) $orderId]);
        }
    }

    public function openPartialReturn(int $orderId): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched') {
            return;
        }

        $order = Order::query()
            ->with(['items:id,order_id,name,quantity,product_image,returned_quantity'])
            ->find($orderId);

        if (! $order || $order->status !== 'dispatched') {
            return;
        }

        $this->partialOrderId = (int) $order->id;
        $this->partialOrderNumber = (string) $order->order_number;
        $this->partialCollectedTk = (string) (int) round((float) $order->cod_amount);
        $this->partialReturns = [];
        $this->partialItems = [];

        foreach ($order->items as $item) {
            $this->partialItems[] = [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'image' => $item->imageUrl(),
            ];
            $this->partialReturns[$item->id] = 0;
        }

        $this->showPartialModal = true;
        $this->resetErrorBag();
    }

    public function closePartialModal(): void
    {
        $this->showPartialModal = false;
        $this->partialOrderId = null;
        $this->partialOrderNumber = '';
        $this->partialReturns = [];
        $this->partialItems = [];
        $this->partialCollectedTk = '0';
        $this->resetErrorBag();
    }

    public function submitPartialReturn(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched' || ! $this->partialOrderId) {
            return;
        }

        $this->validate([
            'partialCollectedTk' => ['required', 'numeric', 'min:0'],
            'partialReturns' => ['required', 'array'],
            'partialReturns.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = Order::query()->find($this->partialOrderId);

        if (! $order || $order->status !== 'dispatched') {
            $this->closePartialModal();

            return;
        }

        $returned = [];
        foreach ($this->partialReturns as $itemId => $qty) {
            $returned[(int) $itemId] = (int) $qty;
        }

        $settlement->partialReturn(
            $order,
            $returned,
            (float) $this->partialCollectedTk,
        );

        $settledId = (int) $order->id;
        $this->closePartialModal();
        $this->removeSettledFromList([$settledId]);
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function removeSettledFromList(array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return;
        }

        $this->selected = array_values(array_diff($this->selected, $orderIds));

        foreach ($orderIds as $orderId) {
            unset($this->courierLiveStatuses[$orderId]);
        }

        $this->listRevision++;

        if ($this->getPage() > 1 && $this->filteredOrdersQuery()->forPage($this->getPage(), 20)->count() === 0) {
            $this->previousPage();
        }
    }

    public function openSendTo(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'new' || $this->selected === []) {
            return;
        }

        $this->showSendToModal = true;
        $this->showDispatchModal = false;
        $this->showBulkSendProgress = false;
        $this->bulkSendRows = [];
        $this->bulkSending = false;
    }

    public function openDispatch(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'new' || $this->selected === []) {
            return;
        }

        $courierIds = Order::query()
            ->whereIn('id', $this->selected)
            ->whereNotNull('courier_id')
            ->distinct()
            ->pluck('courier_id');

        if ($courierIds->count() === 1) {
            $this->dispatchCourierId = (int) $courierIds->first();
        } elseif (! $this->dispatchCourierId) {
            $this->dispatchCourierId = Courier::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->value('id');
        }

        $this->showDispatchModal = true;
        $this->showSendToModal = false;
        $this->showBulkSendProgress = false;
        $this->resetErrorBag('dispatchCourierId');
    }

    public function closeSendModals(): void
    {
        $this->showSendToModal = false;
        $this->showDispatchModal = false;
        $this->showBulkSendProgress = false;
        $this->bulkSending = false;
        $this->bulkSendRows = [];
        $this->closePartialModal();
    }

    public function submitBulkDispatch(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'new' || $this->selected === []) {
            return;
        }

        $this->validate([
            'dispatchCourierId' => ['required', 'integer', 'exists:couriers,id'],
        ]);

        $orders = Order::query()
            ->whereIn('id', $this->selected)
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if (! in_array($order->status, ['new', 'confirmed'], true)) {
                continue;
            }

            $dispatch->markAsDispatched($order, (int) $this->dispatchCourierId);
        }

        $this->selected = [];
        $this->showDispatchModal = false;
    }

    public function startBulkSend(CourierApiRegistry $courierRegistry): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'new' || $this->selected === []) {
            return;
        }

        $this->validate([
            'sendToCourierSlug' => ['required', 'string', 'max:64'],
        ]);

        if (! $courierRegistry->isConfigured($this->sendToCourierSlug)) {
            $this->addError('sendToCourierSlug', 'That courier API is not configured.');

            return;
        }

        $orders = Order::query()
            ->whereIn('id', $this->selected)
            ->orderBy('id')
            ->get(['id', 'order_number', 'name', 'status', 'courier_tracker']);

        if ($orders->isEmpty()) {
            return;
        }

        $this->bulkSendRows = [];

        foreach ($orders as $order) {
            $this->bulkSendRows[$order->id] = [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'customer' => (string) $order->name,
                'status' => 'pending',
                'message' => null,
                'tracker' => null,
            ];
        }

        $this->showSendToModal = false;
        $this->showBulkSendProgress = true;
        $this->bulkSending = true;

        $this->js('setTimeout(() => $wire.dispatchNextSelected(), 50)');
    }

    public function dispatchNextSelected(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->bulkSending || $this->sendToCourierSlug === '') {
            return;
        }

        $nextId = null;

        foreach ($this->bulkSendRows as $orderId => $row) {
            if (($row['status'] ?? '') === 'pending') {
                $nextId = (int) $orderId;
                break;
            }
        }

        if ($nextId === null) {
            $this->bulkSending = false;

            return;
        }

        $this->bulkSendRows[$nextId]['status'] = 'sending';
        $this->bulkSendRows[$nextId]['message'] = null;

        try {
            $order = Order::query()->findOrFail($nextId);

            if (! $order->isDispatchable()) {
                throw new \RuntimeException(
                    $order->courier_tracker
                        ? 'Already has tracking code.'
                        : 'Order status does not allow dispatch.'
                );
            }

            $order = $dispatch->dispatchViaApi($order, $this->sendToCourierSlug, markDispatched: false);

            $this->bulkSendRows[$nextId]['status'] = 'success';
            $this->bulkSendRows[$nextId]['tracker'] = (string) $order->courier_tracker;
            $this->bulkSendRows[$nextId]['message'] = 'Tracking: '.$order->courier_tracker;

            $this->selected = array_values(array_diff($this->selected, [$nextId]));
        } catch (\Throwable $e) {
            $this->bulkSendRows[$nextId]['status'] = 'failed';
            $this->bulkSendRows[$nextId]['message'] = $e->getMessage();
        }

        $hasPending = collect($this->bulkSendRows)->contains(fn (array $row) => ($row['status'] ?? '') === 'pending');

        if ($hasPending) {
            $this->js('setTimeout(() => $wire.dispatchNextSelected(), 50)');
        } else {
            $this->bulkSending = false;
        }
    }

    private function pageOrderIds(): array
    {
        return $this->filteredOrdersQuery()
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function filteredOrdersQuery(): Builder
    {
        return AdminOrderSegment::apply(
            Order::query()
                ->when($this->search !== '', function ($q) {
                    $term = '%'.$this->search.'%';
                    $q->where(function ($inner) use ($term) {
                        $inner->where('order_number', 'like', $term)
                            ->orWhere('name', 'like', $term)
                            ->orWhere('phone', 'like', $term);
                    });
                }),
            $this->segment
        )
            ->latest('placed_at')
            ->latest('id');
    }

    public function updatedPage(): void
    {
        $this->closeProductImage();
        $this->courierLiveStatuses = [];
        $this->queueCourierStatusRefresh();
    }

    public function refreshCourierStatuses(CourierTrackingService $tracking): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'dispatched') {
            return;
        }

        $orders = $this->filteredOrdersQuery()
            ->with(['courier:id,name,slug', 'courierLogs'])
            ->forPage($this->getPage(), 20)
            ->get();

        $statuses = [];

        foreach ($orders as $order) {
            $statuses[$order->id] = $tracking->fetchAndRecordLiveStatus($order);
            $order->unsetRelation('courierLogs');
            $order->load('courierLogs');
        }

        $this->courierLiveStatuses = $statuses;
        $this->listRevision++;
    }

    /**
     * Schedule a client-side tracking refresh after the current response
     * (avoids blocking the first paint / segment switch on courier APIs).
     */
    private function queueCourierStatusRefresh(): void
    {
        if ($this->segment !== 'dispatched' || AdminAccess::isModeratorOnly()) {
            return;
        }

        if (app(CourierApiRegistry::class)->configuredSlugs() === []) {
            return;
        }

        $this->js('setTimeout(() => $wire.refreshCourierStatuses(), 0)');
    }

    public function render(CourierApiRegistry $courierRegistry, CourierTrackingService $tracking)
    {
        $itemColumns = 'id,order_id,name,quantity,product_image,product_id';

        if ($this->segment === 'return-pending') {
            $itemColumns .= ',returned_quantity,to_be_returned,return_received';
        }

        $with = [
            'courier:id,name,slug',
            'items:'.$itemColumns,
        ];

        if ($this->segment === 'dispatched') {
            $with[] = 'courierLogs';
        }

        $orders = $this->filteredOrdersQuery()
            ->with($with)
            ->simplePaginate(20);

        $selectedIds = $this->selected;
        $selectedCount = count($selectedIds);
        $selectedTotal = $selectedCount > 0
            ? (float) Order::query()->whereIn('id', $selectedIds)->sum('total')
            : 0.0;
        $pageOrderIds = $orders->pluck('id')->map(fn ($id) => (int) $id)->all();
        $isPageFullySelected = $pageOrderIds !== []
            && count(array_intersect($pageOrderIds, $selectedIds)) === count($pageOrderIds);

        $apiCouriers = Courier::query()
            ->where('is_active', true)
            ->whereIn('slug', $courierRegistry->configuredSlugs())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $dispatchCouriers = Courier::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);

        $trackingByOrder = [];

        if ($this->segment === 'dispatched') {
            foreach ($orders as $order) {
                $live = $this->courierLiveStatuses[$order->id] ?? null;
                $trackingByOrder[$order->id] = [
                    'status' => $tracking->displayStatus($order, is_string($live) ? $live : null),
                    'events' => $tracking->eventsFromLoadedLogs($order),
                ];
            }
        }

        return view('livewire.admin.admin-orders', [
            'orders' => $orders,
            'segment' => $this->segment,
            'segmentLabel' => AdminOrderSegment::label($this->segment),
            'segments' => AdminOrderSegment::SEGMENTS,
            'isPageFullySelected' => $isPageFullySelected,
            'selectedIds' => $selectedIds,
            'selectedCount' => $selectedCount,
            'selectedTotal' => $selectedTotal,
            'apiCouriers' => $apiCouriers,
            'dispatchCouriers' => $dispatchCouriers,
            'trackingByOrder' => $trackingByOrder,
            'courierApiAvailable' => $courierRegistry->configuredSlugs() !== [],
            'readOnly' => AdminAccess::isModeratorOnly(),
        ])->title(AdminOrderSegment::label($this->segment).' Orders');
    }
}