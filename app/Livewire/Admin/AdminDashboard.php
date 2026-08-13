<?php

namespace App\Livewire\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Expense;
use App\Models\ExpenseRecurringReminder;
use App\Models\Order;
use App\Services\Admin\AdminAttentionService;
use App\Services\Admin\CourierBalanceService;
use App\Services\Admin\ExpenseAssistantService;
use App\Services\Admin\OrderDeliveryReturnService;
use App\Services\Admin\OrderPackagingCourierConfirmService;
use App\Services\Admin\ReturnHubArrivalService;
use App\Services\Admin\SteadfastWebhookInboxService;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPackagingCost;
use App\Support\AdminAccess;
use App\Support\AdminDashboardMetrics;
use App\Support\AdminOrderSegment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admin Dashboard')]
#[Layout('components.layouts.admin')]
class AdminDashboard extends Component
{
    /** @var array<int|string, string> */
    public array $pendingCourierCharges = [];

    /** @var array<int|string, string> */
    public array $pendingPackagingCosts = [];

    public ?string $courierChargeMessage = null;

    public ?string $returnHubMessage = null;

    public ?string $expenseAssistantMessage = null;

    /** @var array<int|string, string> */
    public array $expenseReminderAmounts = [];

    public bool $showEveningExpenseForm = false;

    public string $eveningExpenseTitle = '';

    public string $eveningExpenseAmount = '';

    public string $eveningExpenseCategory = 'other';

    /** @var 'last7'|'current'|'previous' */
    public string $ordersDateRange = AdminDashboardMetrics::RANGE_LAST7;

    public function mount(): void
    {
        if (AdminAccess::isModeratorOnly()) {
            $this->redirect(route('admin.orders.new'), navigate: true);
        }
    }

    public function showOrdersDateRange(string $range): void
    {
        if (! in_array($range, [
            AdminDashboardMetrics::RANGE_LAST7,
            AdminDashboardMetrics::RANGE_CURRENT,
            AdminDashboardMetrics::RANGE_PREVIOUS,
        ], true)) {
            return;
        }

        $this->ordersDateRange = $range;
    }

    public function markResolved(int $itemId): void
    {
        $item = AdminAttentionItem::findOrFail($itemId);
        app(AdminAttentionService::class)->markAsResolved($item, 'Resolved from dashboard');

        $this->dispatch('attention-item-resolved');
    }

    public function dismissSteadfastWebhook(int $entryId, SteadfastWebhookInboxService $webhookInbox): void
    {
        AdminAccess::ensureStaffAdmin();

        $entry = CourierData::query()->whereKey($entryId)->first();

        if (! $entry) {
            return;
        }

        $webhookInbox->dismiss($entry);
    }

    public function recordExpenseReminder(int $reminderId, ExpenseAssistantService $assistant): void
    {
        AdminAccess::ensureStaffAdmin();

        $reminder = ExpenseRecurringReminder::query()->whereKey($reminderId)->where('is_active', true)->firstOrFail();
        $amountKey = 'expenseReminderAmounts.'.$reminderId;

        if (! array_key_exists($reminderId, $this->expenseReminderAmounts) || $this->expenseReminderAmounts[$reminderId] === '') {
            $this->expenseReminderAmounts[$reminderId] = $reminder->default_amount !== null
                ? (string) (int) round((float) $reminder->default_amount)
                : '';
        }

        $this->validate([
            $amountKey => ['required', 'numeric', 'min:0.01'],
        ], [], [
            $amountKey => 'amount',
        ]);

        $assistant->recordPayment(
            reminder: $reminder,
            amount: (float) $this->expenseReminderAmounts[$reminderId],
            user: auth()->user(),
        );

        unset($this->expenseReminderAmounts[$reminderId]);
        $this->expenseAssistantMessage = $reminder->title.' recorded.';
    }

    public function markExpenseReminderChecked(int $reminderId, ExpenseAssistantService $assistant): void
    {
        AdminAccess::ensureStaffAdmin();

        $reminder = ExpenseRecurringReminder::query()
            ->whereKey($reminderId)
            ->where('prompt_type', ExpenseRecurringReminder::PROMPT_CHECK)
            ->where('is_active', true)
            ->firstOrFail();

        $assistant->markChecked($reminder, auth()->user());
        $this->expenseAssistantMessage = $reminder->title.' marked as checked.';
    }

    public function skipExpenseReminder(int $reminderId, ExpenseAssistantService $assistant): void
    {
        AdminAccess::ensureStaffAdmin();

        $reminder = ExpenseRecurringReminder::query()->whereKey($reminderId)->where('is_active', true)->firstOrFail();
        $assistant->skipReminder($reminder, auth()->user());
        unset($this->expenseReminderAmounts[$reminderId]);
        $this->expenseAssistantMessage = $reminder->title.' skipped for this month.';
    }

    public function openEveningExpenseForm(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->showEveningExpenseForm = true;
        $this->eveningExpenseTitle = '';
        $this->eveningExpenseAmount = '';
        $this->eveningExpenseCategory = 'other';
    }

    public function dismissEveningExpensePrompt(ExpenseAssistantService $assistant): void
    {
        AdminAccess::ensureStaffAdmin();
        $assistant->dismissEvening(auth()->user());
        $this->showEveningExpenseForm = false;
        $this->expenseAssistantMessage = 'Evening reminder dismissed for tonight.';
    }

    public function saveEveningExpense(ExpenseAssistantService $assistant): void
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $this->validate([
            'eveningExpenseTitle' => ['required', 'string', 'max:160'],
            'eveningExpenseAmount' => ['required', 'numeric', 'min:0.01'],
            'eveningExpenseCategory' => ['required', 'in:'.implode(',', array_keys(Expense::CATEGORIES))],
        ], [], [
            'eveningExpenseTitle' => 'title',
            'eveningExpenseAmount' => 'amount',
            'eveningExpenseCategory' => 'category',
        ]);

        $assistant->recordOneOff(
            title: $validated['eveningExpenseTitle'],
            amount: (float) $validated['eveningExpenseAmount'],
            category: $validated['eveningExpenseCategory'],
            user: auth()->user(),
        );
        $assistant->dismissEvening(auth()->user());

        $this->showEveningExpenseForm = false;
        $this->eveningExpenseTitle = '';
        $this->eveningExpenseAmount = '';
        $this->eveningExpenseCategory = 'other';
        $this->expenseAssistantMessage = 'Expense recorded.';
    }

    public function applyCourierChargePreset(int $orderId, int $amount): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($amount < 0) {
            return;
        }

        $exists = Order::query()
            ->whereKey($orderId)
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->exists();

        if (! $exists) {
            return;
        }

        $this->pendingCourierCharges[$orderId] = (string) $amount;
        $this->resetValidation('pendingCourierCharges.'.$orderId);
    }

    public function markReturnHubReceived(int $orderId, OrderDeliveryReturnService $settlement): void
    {
        AdminAccess::ensureStaffAdmin();

        $order = Order::query()
            ->whereKey($orderId)
            ->where('has_return', true)
            ->whereNotNull('return_hub_arrived_at')
            ->first();

        if (! $order) {
            return;
        }

        $settlement->markReturnReceived($order);
        $this->returnHubMessage = 'Return marked received for order #'.$order->order_number.'.';
    }

    public function confirmCourierCharge(
        int $orderId,
        OrderCourierChargeSync $courierChargeSync,
        OrderPackagingCost $packagingCost,
        OrderPackagingCourierConfirmService $confirmService,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $order = Order::query()
            ->with('items:id,order_id,quantity')
            ->whereKey($orderId)
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->firstOrFail();

        $chargeRaw = $this->pendingCourierCharges[$orderId]
            ?? (string) (int) round($courierChargeSync->suggestedConfirmAmount($order));
        $packagingRaw = $this->pendingPackagingCosts[$orderId]
            ?? (string) (int) round($packagingCost->suggestedAmount($order));

        $this->pendingCourierCharges[$orderId] = $chargeRaw;
        $this->pendingPackagingCosts[$orderId] = $packagingRaw;

        $this->validate([
            'pendingCourierCharges.'.$orderId => ['required', 'numeric', 'min:0'],
            'pendingPackagingCosts.'.$orderId => ['required', 'numeric', 'min:0'],
        ], [], [
            'pendingCourierCharges.'.$orderId => 'courier charge',
            'pendingPackagingCosts.'.$orderId => 'packaging cost',
        ]);

        $confirmService->confirm(
            order: $order,
            packagingAmount: (float) $packagingRaw,
            courierAmount: (float) $chargeRaw,
            actor: auth()->user(),
        );

        unset($this->pendingCourierCharges[$orderId], $this->pendingPackagingCosts[$orderId]);
        $this->courierChargeMessage = 'Courier charge and packaging updated for '.$order->name.'.';
    }

    public function render(OrderCourierChargeSync $courierChargeSync, OrderPackagingCost $packagingCost)
    {
        if (AdminAccess::isModeratorOnly()) {
            return view('livewire.admin.admin-dashboard', [
                'segments' => [],
                'segmentCounts' => [],
                'segmentValues' => [],
                'orderMonthTiles' => [],
                'ordersDatePanel' => [
                    'key' => 'last7',
                    'range' => AdminDashboardMetrics::RANGE_LAST7,
                    'label' => 'Last 7 days',
                    'days' => [],
                    'totals' => [
                        'order_qty' => 0,
                        'order_value' => 0,
                        'delivery_qty' => 0,
                        'delivery_value' => 0,
                    ],
                ],
                'ordersByCategory' => [
                    'this_month' => ['key' => '', 'label' => 'This month'],
                    'last_month' => ['key' => '', 'label' => 'Last month'],
                    'rows' => [],
                ],
                'attentionSummary' => [
                    'unresolved_count' => 0,
                    'unresolved_items' => collect(),
                    'recent_resolved' => collect(),
                    'unresolved_by_type' => [],
                ],
                'unconfirmedCourierCharges' => collect(),
                'courierChargeAreaLabels' => [],
                'courierChargeQuickAmounts' => [],
                'returnHubArrivals' => collect(),
                'steadfastWebhookInbox' => collect(),
                'steadfastWebhookSummaries' => [],
                'dueExpenseReminders' => collect(),
                'showEveningExpensePrompt' => false,
                'expenseCategories' => Expense::CATEGORIES,
                'steadfastExpectedApiBalance' => null,
            ]);
        }

        $segmentCounts = AdminOrderSegment::counts();
        $segmentValues = AdminOrderSegment::values();
        $orderActivity = AdminDashboardMetrics::orderActivity();
        $orderMonthTiles = $orderActivity['months'];
        $ordersDatePanel = match ($this->ordersDateRange) {
            AdminDashboardMetrics::RANGE_CURRENT => $orderMonthTiles[0],
            AdminDashboardMetrics::RANGE_PREVIOUS => $orderMonthTiles[1],
            default => $orderActivity['last7'],
        };
        $ordersByCategory = AdminDashboardMetrics::orderAndDeliveryByCategory();
        $expenseAssistant = app(ExpenseAssistantService::class);
        $dueExpenseReminders = $expenseAssistant->dueReminders(auth()->user());
        $showEveningExpensePrompt = $expenseAssistant->shouldShowEveningPrompt(auth()->user());

        foreach ($dueExpenseReminders as $reminder) {
            if (! array_key_exists($reminder->id, $this->expenseReminderAmounts)) {
                $this->expenseReminderAmounts[$reminder->id] = $reminder->default_amount !== null
                    ? (string) (int) round((float) $reminder->default_amount)
                    : '';
            }
        }

        $attentionService = app(AdminAttentionService::class);
        $attentionSummary = $attentionService->getDashboardSummary();
        $attentionSummary['unresolved_items'] = AdminAttentionItem::unresolved()
            ->with('order')
            ->latest()
            ->limit(10)
            ->get();

        $unconfirmedCourierCharges = Order::query()
            ->with([
                'courier:id,name,slug,charge,osd_charge',
                'items:id,order_id,name,quantity,product_image',
            ])
            ->where('status', 'dispatched')
            ->whereNull('courier_charge_confirmed_at')
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'order_number', 'name', 'city', 'area', 'courier_id', 'courier_tracker', 'courier_consignment_id', 'courier_charge', 'packaging_cost', 'dispatch_date', 'status']);

        $courierChargeAreaLabels = [];
        $courierChargeQuickAmounts = [];

        foreach ($unconfirmedCourierCharges as $order) {
            $suggested = $courierChargeSync->suggestedConfirmAmount($order);
            $courierChargeAreaLabels[$order->id] = $courierChargeSync->areaRateLabel($order);
            $courierChargeQuickAmounts[$order->id] = $courierChargeSync->quickConfirmAmounts($order);

            if (! array_key_exists($order->id, $this->pendingCourierCharges)) {
                $this->pendingCourierCharges[$order->id] = (string) (int) round($suggested);
            }

            if (! array_key_exists($order->id, $this->pendingPackagingCosts)) {
                $this->pendingPackagingCosts[$order->id] = (string) (int) round($packagingCost->suggestedAmount($order));
            }
        }

        $returnHubArrivals = app(ReturnHubArrivalService::class)->ordersAwaitingReceive();

        $webhookInbox = app(SteadfastWebhookInboxService::class);
        $steadfastWebhookInbox = $webhookInbox->latestIncoming();
        $steadfastWebhookSummaries = [];
        foreach ($steadfastWebhookInbox as $entry) {
            $steadfastWebhookSummaries[$entry->id] = $webhookInbox->summary($entry);
        }

        $steadfast = Courier::query()->where('slug', 'steadfast')->first();
        $steadfastExpectedApiBalance = $steadfast
            ? (float) app(CourierBalanceService::class)->summarize($steadfast)['expected_api']
            : null;

        return view('livewire.admin.admin-dashboard', [
            'segments' => AdminOrderSegment::SEGMENTS,
            'segmentCounts' => $segmentCounts,
            'segmentValues' => $segmentValues,
            'orderMonthTiles' => $orderMonthTiles,
            'ordersDatePanel' => $ordersDatePanel,
            'ordersByCategory' => $ordersByCategory,
            'attentionSummary' => $attentionSummary,
            'unconfirmedCourierCharges' => $unconfirmedCourierCharges,
            'courierChargeAreaLabels' => $courierChargeAreaLabels,
            'courierChargeQuickAmounts' => $courierChargeQuickAmounts,
            'returnHubArrivals' => $returnHubArrivals,
            'steadfastWebhookInbox' => $steadfastWebhookInbox,
            'steadfastWebhookSummaries' => $steadfastWebhookSummaries,
            'dueExpenseReminders' => $dueExpenseReminders,
            'showEveningExpensePrompt' => $showEveningExpensePrompt,
            'expenseCategories' => Expense::CATEGORIES,
            'steadfastExpectedApiBalance' => $steadfastExpectedApiBalance,
        ]);
    }
}
