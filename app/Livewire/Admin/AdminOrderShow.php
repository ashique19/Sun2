<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Admin\OrderDispatchService;
use App\Services\Admin\OrderStatusService;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPaymentRecorder;
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

    public string $paymentAmount = '';

    public string $paymentMethod = 'cash';

    public string $paymentKind = 'partial';

    public string $paymentNote = '';

    public string $courierChargeOverride = '';

    public string $courierChargeReason = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(Order $order, CourierApiRegistry $courierRegistry): void
    {
        AdminAccess::ensureCanViewOrder($order);

        $this->order = $order->load([
            'items.product:id,slug,name',
            'items.product.images:id,product_id,path,is_primary,sort_order',
            'coupon',
            'adjustments',
            'adjustmentLogs.actor',
            'paymentTransactions.receivedBy',
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
        $this->courierChargeOverride = (string) (int) round((float) $order->courier_charge);

        $defaultPaymentMethod = PaymentMethod::query()->active()->value('code');
        $this->paymentMethod = is_string($defaultPaymentMethod) && $defaultPaymentMethod !== ''
            ? $defaultPaymentMethod
            : 'cash';

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
        AdminAccess::ensureStaffAdmin();

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
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'apiCourierSlug' => ['required', 'string', 'max:64'],
        ]);

        try {
            $this->order = $dispatch->dispatchViaApi($this->order, $this->apiCourierSlug);
            $this->status = $this->order->status;
            $this->order->load(['courier', 'statusHistory.changedBy', 'courierLogs.courier', 'adjustments', 'adjustmentLogs.actor', 'paymentTransactions.receivedBy']);
            $this->message = 'Dispatched via '.$this->order->courier?->name.'. Tracking: '.$this->order->courier_tracker;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function dispatchSteadfast(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->apiCourierSlug = 'steadfast';
        $this->dispatchViaApi($dispatch);
    }

    public function dispatchManual(OrderDispatchService $dispatch): void
    {
        AdminAccess::ensureStaffAdmin();

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

    public function recordPayment(OrderPaymentRecorder $recorder): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethod' => ['required', 'string', 'max:32', 'exists:payment_methods,code'],
            'paymentKind' => ['required', 'string', 'in:advance,partial,settlement'],
            'paymentNote' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = filled($this->paymentNote) ? ['note' => $this->paymentNote] : null;

        $recorder->record(
            order: $this->order,
            method: $this->paymentMethod,
            amount: (float) $this->paymentAmount,
            kind: $this->paymentKind,
            reference: null,
            actor: auth()->user(),
            meta: $meta,
        );

        $this->order->refresh()->load(['adjustmentLogs.actor', 'paymentTransactions.receivedBy']);
        $this->paymentAmount = '';
        $this->paymentNote = '';
        $this->message = 'Payment recorded.';
    }

    public function updateCourierCharge(OrderCourierChargeSync $courierSync): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'courierChargeOverride' => ['required', 'numeric', 'min:0'],
            'courierChargeReason' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = filled($this->courierChargeReason)
            ? ['reason' => $this->courierChargeReason]
            : null;

        $courierSync->set(
            order: $this->order,
            amount: (float) $this->courierChargeOverride,
            phase: 'manual',
            actor: auth()->user(),
            meta: $meta,
        );

        $this->order->refresh()->load(['adjustmentLogs.actor']);
        $this->courierChargeOverride = (string) (int) round((float) $this->order->courier_charge);
        $this->courierChargeReason = '';
        $this->message = 'Courier charge updated.';
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
            'paymentMethods' => PaymentMethod::query()->active()->get(['id', 'name', 'code']),
            'readOnly' => AdminAccess::isModeratorOnly(),
        ])->title($this->title());
    }
}
