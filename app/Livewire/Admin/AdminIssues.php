<?php

namespace App\Livewire\Admin;

use App\Models\AdminAttentionItem;
use App\Services\Admin\AdminAttentionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Admin Issues')]
#[Layout('components.layouts.admin')]
class AdminIssues extends Component
{
    use WithPagination;

    public string $statusFilter = 'all'; // all, unresolved, resolved

    public string $issueTypeFilter = 'all';

    public string $search = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $selectedItems = [];

    public function mount(): void
    {
        // Set default date range to last 30 days
        $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function markResolved(int $itemId, ?string $notes = null): void
    {
        $item = AdminAttentionItem::findOrFail($itemId);
        app(AdminAttentionService::class)->markAsResolved($item, $notes);

        $this->dispatch('attention-item-resolved');
        $this->resetPage();
    }

    public function markSelectedResolved(): void
    {
        if (empty($this->selectedItems)) {
            return;
        }

        $items = AdminAttentionItem::whereIn('id', $this->selectedItems)->get();
        $service = app(AdminAttentionService::class);

        foreach ($items as $item) {
            $service->markAsResolved($item, 'Bulk resolution');
        }

        $this->selectedItems = [];
        $this->dispatch('attention-items-resolved');
        $this->resetPage();
    }

    public function render()
    {
        $query = AdminAttentionItem::query()
            ->with(['order', 'resolvedBy'])
            ->latest();

        // Apply status filter
        if ($this->statusFilter === 'unresolved') {
            $query->unresolved();
        } elseif ($this->statusFilter === 'resolved') {
            $query->resolved();
        }

        // Apply issue type filter
        if ($this->issueTypeFilter !== 'all') {
            $query->byIssueType($this->issueTypeFilter);
        }

        // Apply search
        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('description', 'like', '%'.$this->search.'%')
                    ->orWhereHas('order', function ($orderQuery) {
                        $orderQuery->where('order_number', 'like', '%'.$this->search.'%');
                    });
            });
        }

        // Apply date range filter
        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $items = $query->paginate(20);

        $summary = [
            'total' => AdminAttentionItem::count(),
            'unresolved' => AdminAttentionItem::unresolved()->count(),
            'resolved' => AdminAttentionItem::resolved()->count(),
            'by_type' => [],
        ];

        foreach (AdminAttentionItem::ISSUE_TYPES as $type) {
            $summary['by_type'][$type] = [
                'total' => AdminAttentionItem::byIssueType($type)->count(),
                'unresolved' => AdminAttentionItem::unresolved()->byIssueType($type)->count(),
            ];
        }

        return view('livewire.admin.admin-issues', [
            'items' => $items,
            'summary' => $summary,
            'issueTypes' => AdminAttentionItem::ISSUE_TYPE_LABELS,
        ]);
    }
}
