<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Admin\AnalyticsService;
use App\Support\AdminAccess;
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

    public ?int $editingOrderId = null;

    public ?string $editingField = null;

    public string $editingValue = '';

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

    public function startInlineEdit(int $orderId, string $field, string $value = ''): void
    {
        if (! in_array($field, self::INLINE_FIELDS, true)) {
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
            $this->applyCogsOverride($order, $amount);
        } else {
            $order->update([$field => $amount]);
        }

        $this->cancelInlineEdit();
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
            ->orderByDesc('id')
            ->paginate(50);

        /** @var array<int, array{revenue: float, cogs: float, packaging: float, courier: float, cod: float, direct: float, profit: float}> $economicsById */
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
