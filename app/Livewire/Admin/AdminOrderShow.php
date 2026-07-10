<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Admin\OrderDispatchService;
use App\Services\Admin\OrderStatusService;
use App\Services\Couriers\CourierApiRegistry;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminOrderShow extends Component
{
    use ManagesProductImagePreview;

    public Order $order;

    public string $status = '';

    public string $adminNote = '';

    public ?int $courierId = null;

    public string $apiCourierSlug = '';

    public string $manualTracker = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(Order $order, CourierApiRegistry $courierRegistry): void
    {
        AdminAccess::ensureCanViewOrder($order);

        $this->order = $order->load([
            'items.product:id,slug,name',
            'items.product.images:id,product_id,path,is_primary,sort_order',
            'coupon',
            'courier',
            'statusHistory.changedBy',
            'courierLogs.courier',
        ]);
        $this->status = (string) $order->status;
        $this->adminNote = (string) ($order->admin_note ?? '');
        $this->courierId = $order->courier_id
            ?? Courier::query()->where('is_active', true)->where('is_default', true)->value('id')
            ?? Courier::query()->where('is_active', true)->where('slug', 'steadfast')->value('id')
            ?? Courier::query()->where('is_active', true)->orderBy('name')->value('id');

        $apiCouriers = Courier::query()
            ->where('is_active', true)
            ->whereIn('slug', $courierRegistry->configuredSlugs())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $this->apiCourierSlug = (string) (
            $apiCouriers->firstWhere('is_default', true)?->slug
            ?? $apiCouriers->firstWhere('slug', 'steadfast')?->slug
            ?? $apiCouriers->first()?->slug
            ?? ''
        );
    }

    public function title(): string
    {
        return 'Order #'.$this->order->order_number;
    }

    public function saveStatus(OrderStatusService $statusService): void
    {
        AdminAccess::ensureCanManageOrders();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'status' => ['required', 'string', 'max:64'],
            'adminNote' => ['nullable', 'string', 'max:5000'],
        ]);

        $previousStatus = (string) $this->order->status;
        $previousNote = (string) ($this->order->admin_note ?? '');
        $newNote = $this->adminNote ?: null;

        $this->order->update(['admin_note' => $newNote]);

        if ($this->status !== $previousStatus) {
            $this->order = $statusService->update(
                $this->order,
                $this->status,
                'Status updated from admin.',
            );
            $this->status = $this->order->status;
        } elseif ($previousNote !== (string) ($newNote ?? '')) {
            $statusService->record($this->order, 'Admin note updated.');
        }

        $this->order->refresh()->load(['statusHistory.changedBy']);
        $this->message = 'Order updated.';
    }

    public function dispatchViaApi(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureCanManageOrders();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'apiCourierSlug' => ['required', 'string', 'max:64'],
        ]);

        try {
            $this->order = $dispatch->dispatchViaApi($this->order, $this->apiCourierSlug);
            $this->status = $this->order->status;
            $this->order->load(['courier', 'statusHistory.changedBy', 'courierLogs.courier']);
            $this->message = 'Dispatched via '.$this->order->courier?->name.'. Tracking: '.$this->order->courier_tracker;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function dispatchSteadfast(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureCanManageOrders();

        $this->apiCourierSlug = 'steadfast';
        $this->dispatchViaApi($dispatch);
    }

    public function dispatchManual(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureCanManageOrders();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'courierId' => ['required', 'integer', 'exists:couriers,id'],
            'manualTracker' => ['required', 'string', 'max:120'],
        ]);

        try {
            $this->order = $dispatch->assignManual(
                $this->order,
                $this->courierId,
                $this->manualTracker,
            );
            $this->status = $this->order->status;
            $this->manualTracker = '';
            $this->order->load(['courier', 'statusHistory.changedBy']);
            $this->message = 'Courier assigned manually.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(CourierApiRegistry $courierRegistry)
    {
        $apiCouriers = Courier::query()
            ->where('is_active', true)
            ->whereIn('slug', $courierRegistry->configuredSlugs())
            ->orderBy('name')
            ->get();

        return view('livewire.admin.admin-order-show', [
            'couriers' => Courier::query()->where('is_active', true)->orderBy('name')->get(),
            'apiCouriers' => $apiCouriers,
            'readOnly' => AdminAccess::isModeratorOnly(),
        ])->title($this->title());
    }
}
