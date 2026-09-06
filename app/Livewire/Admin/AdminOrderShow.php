<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ManagesProductImagePreview;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\OrderDispatchService;
use App\Services\Admin\OrderStatusService;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Channels\ChannelReplyService;
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

    public string $customerNote = '';

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

    public bool $showConversation = false;

    public string $replyText = '';

    public bool $showPartialModal = false;

    /** @var array<int|string, int|string> */
    public array $partialReturns = [];

    public string $partialCollectedTk = '0';

    public string $partialExpectedCod = '0';

    public string $partialCourierCharge = '0';

    /** dispatched = rider partial; delivered = post-delivery H/R. */
    public string $partialMode = 'dispatched';

    /** @var list<array{id:int,name:string,quantity:int,image:?string}> */
    public array $partialItems = [];

    public function mount(Order $order, CourierApiRegistry $courierRegistry, OrderCourierChargeSync $courierChargeSync): void
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
            'courierChargeConfirmedBy:id,name',
            'createdBy:id,name',
            'statusHistory.changedBy',
            'courierLogs.courier',
            'channelConversation.messages',
            'exchangeOf:id,order_number',
            'replacements:id,order_number,exchange_of_order_id',
            'user:id,name,phone',
        ]);
        $this->status = (string) $order->status;
        $this->adminNote = (string) ($order->admin_note ?? '');
        $this->customerNote = (string) ($order->customer_note ?? '');
        $this->courierId = $order->courier_id
            ?? Courier::query()->where('is_active', true)->where('is_default', true)->value('id')
            ?? Courier::query()->where('is_active', true)->where('slug', 'steadfast')->value('id')
            ?? Courier::query()->where('is_active', true)->orderBy('name')->value('id');

        $chargeDefault = $order->isCourierChargeConfirmed()
            ? (float) $order->courier_charge
            : $courierChargeSync->suggestedConfirmAmount($this->order);
        $this->courierChargeOverride = (string) (int) round($chargeDefault);

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

    public function saveCustomerNote(OrderStatusService $statusService): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'customerNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $previous = (string) ($this->order->customer_note ?? '');
        $newNote = trim($this->customerNote) !== '' ? trim($this->customerNote) : null;

        $this->order->update(['customer_note' => $newNote]);

        if ($previous !== (string) ($newNote ?? '')) {
            $statusService->record($this->order, 'Customer note updated.');
        }

        $this->order->refresh()->load(['statusHistory.changedBy']);
        $this->customerNote = (string) ($this->order->customer_note ?? '');
        $this->message = 'Customer note saved.';
    }

    public function clearCustomerNote(OrderStatusService $statusService): void
    {
        $this->customerNote = '';
        $this->saveCustomerNote($statusService);
    }

    public function confirmDraft(ChannelOrderDraftService $drafts): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if (! $this->order->isAiDraft()) {
            $this->error = 'This order is not an AI draft.';

            return;
        }

        try {
            $this->order = $drafts->confirm($this->order, auth()->id());
            $this->status = $this->order->status;
            $this->order->load([
                'items.product:id,slug,name',
                'items.product.images:id,product_id,path,is_primary,sort_order',
                'statusHistory.changedBy',
                'channelConversation.messages',
                'createdBy:id,name',
            ]);
            $this->message = 'Draft confirmed and moved to New.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleConversation(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->showConversation = ! $this->showConversation;
    }

    public function sendConversationReply(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $conversation = $this->order->channelConversation;
        if (! $conversation) {
            $this->error = 'No channel conversation linked to this order.';

            return;
        }

        $result = $replies->sendText($conversation, $this->replyText);
        if (! $result['ok']) {
            $this->error = $result['error'] ?? 'Failed to send reply.';

            return;
        }

        $this->replyText = '';
        $this->order->load('channelConversation.messages');
        $this->showConversation = true;
        $this->message = 'Reply sent.';
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

        $this->order->refresh()->load(['adjustmentLogs.actor', 'courierChargeConfirmedBy']);
        $this->courierChargeOverride = (string) (int) round((float) $this->order->courier_charge);
        $this->courierChargeReason = '';
        $this->message = 'Courier charge updated.';
    }

    public function confirmCourierCharge(OrderCourierChargeSync $courierSync): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->validate([
            'courierChargeOverride' => ['required', 'numeric', 'min:0'],
            'courierChargeReason' => ['nullable', 'string', 'max:500'],
        ]);

        $courierSync->confirm(
            order: $this->order,
            amount: (float) $this->courierChargeOverride,
            actor: auth()->user(),
            reason: $this->courierChargeReason !== '' ? $this->courierChargeReason : null,
        );

        $this->order->refresh()->load(['adjustmentLogs.actor', 'courierChargeConfirmedBy']);
        $this->courierChargeOverride = (string) (int) round((float) $this->order->courier_charge);
        $this->courierChargeReason = '';
        $this->message = 'Courier charge confirmed.';
    }

    public function markDelivered(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if ($this->order->status !== 'dispatched') {
            $this->error = 'Only dispatched orders can be marked delivered.';

            return;
        }

        $settlement->markDelivered($this->order);
        $this->refreshOrderAfterSettlement('Marked delivered.');
    }

    public function cancelAndReturn(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if ($this->order->status !== 'dispatched') {
            $this->error = 'Only dispatched orders can be cancelled and returned.';

            return;
        }

        $settlement->cancelAndReturn($this->order);
        $this->refreshOrderAfterSettlement('Cancelled and returned. Net is courier + packaging loss unless delivery cash was collected.');
    }

    public function markReturnReceived(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if (! $this->order->has_return) {
            $this->error = 'This order is not flagged for return.';

            return;
        }

        $settlement->markReturnReceived($this->order);
        $this->refreshOrderAfterSettlement('Return received. Stock restored where return qty was set.');
    }

    public function undoReturnReceived(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $settlement->undoReturnReceived($this->order);
        $this->refreshOrderAfterSettlement('Return received undone.');
    }

    public function toggleHasReturn(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $this->order->refresh();

        if ($this->order->status === 'delivered' && ! $this->order->has_return) {
            $this->openDeliveredHasReturn();

            return;
        }

        if (! $this->order->has_return) {
            $this->error = 'H/R can only be toggled on return-pending or delivered orders.';

            return;
        }

        $next = ! (bool) $this->order->has_return;
        $settlement->setHasReturn($this->order, $next);
        $this->refreshOrderAfterSettlement($next ? 'Flagged has return.' : 'Cleared has return.');
    }

    public function openPartialReturn(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        $order = $this->order->fresh(['items']);

        if (! $order || $order->status !== 'dispatched') {
            $this->error = 'Partial return is only available for dispatched orders.';

            return;
        }

        $this->partialExpectedCod = (string) (int) round($order->collectableAmount());
        $this->partialCourierCharge = (string) (int) round((float) ($order->courier_charge ?? 0));
        $this->partialCollectedTk = $this->partialExpectedCod;
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

        $this->partialMode = 'dispatched';
        $this->showPartialModal = true;
        $this->resetErrorBag();
    }

    public function openDeliveredHasReturn(): void
    {
        AdminAccess::ensureStaffAdmin();

        $order = $this->order->fresh(['items']);

        if (! $order || $order->status !== 'delivered' || $order->has_return) {
            return;
        }

        $this->partialExpectedCod = '0';
        $this->partialCourierCharge = '0';
        $this->partialCollectedTk = '0';
        $this->partialMode = 'delivered';
        $this->partialReturns = [];
        $this->partialItems = [];

        foreach ($order->items as $item) {
            $this->partialItems[] = [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'image' => $item->imageUrl(),
            ];
            $this->partialReturns[$item->id] = (int) $item->returned_quantity;
        }

        $this->showPartialModal = true;
        $this->resetErrorBag();
    }

    public function closePartialModal(): void
    {
        $this->showPartialModal = false;
        $this->partialReturns = [];
        $this->partialItems = [];
        $this->partialCollectedTk = '0';
        $this->partialExpectedCod = '0';
        $this->partialCourierCharge = '0';
        $this->partialMode = 'dispatched';
        $this->resetErrorBag();
    }

    public function submitPartialReturn(OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if ($this->partialMode === 'delivered') {
            $this->submitDeliveredHasReturn($settlement);

            return;
        }

        $this->validate([
            'partialCollectedTk' => ['required', 'numeric', 'min:0'],
            'partialReturns' => ['required', 'array'],
            'partialReturns.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = $this->order->fresh();

        if (! $order || $order->status !== 'dispatched') {
            $this->closePartialModal();

            return;
        }

        $returned = [];
        foreach ($this->partialReturns as $itemId => $qty) {
            $returned[(int) $itemId] = (int) $qty;
        }

        $settlement->partialReturn($order, $returned, (float) $this->partialCollectedTk);
        $this->closePartialModal();
        $this->refreshOrderAfterSettlement('Partial return saved. Kept items stay delivered; all returned becomes cancelled.');
    }

    private function submitDeliveredHasReturn(OrderDeliveryReturnService $settlement): void
    {
        $this->validate([
            'partialReturns' => ['required', 'array'],
            'partialReturns.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = $this->order->fresh();

        if (! $order || $order->status !== 'delivered' || $order->has_return) {
            $this->closePartialModal();

            return;
        }

        $returned = [];
        foreach ($this->partialReturns as $itemId => $qty) {
            $returned[(int) $itemId] = (int) $qty;
        }

        $settlement->flagDeliveredReturn($order, $returned);
        $this->closePartialModal();
        $this->refreshOrderAfterSettlement('Return flagged (H/R). Order stays delivered until return is received.');
    }

    private function refreshOrderAfterSettlement(string $message): void
    {
        $this->order->refresh()->load([
            'items.product:id,slug,name',
            'items.product.images:id,product_id,path,is_primary,sort_order',
            'adjustments',
            'adjustmentLogs.actor',
            'paymentTransactions.receivedBy',
            'courier',
            'statusHistory.changedBy',
            'courierLogs.courier',
        ]);
        $this->status = (string) $this->order->status;
        $this->message = $message;
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
