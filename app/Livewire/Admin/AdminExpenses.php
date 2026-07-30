<?php

namespace App\Livewire\Admin;

use App\Models\Expense;
use App\Support\AdminAccess;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Expenses')]
#[Layout('components.layouts.admin')]
class AdminExpenses extends Component
{
    use WithPagination;

    #[Url]
    public int $year = 0;

    #[Url]
    public int $month = 0;

    public string $title = '';

    public string $amount = '';

    public string $category = 'other';

    public string $kind = Expense::KIND_ONE_TIME;

    public string $spent_on = '';

    public string $notes = '';

    public ?string $message = null;

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();

        $now = now('Asia/Dhaka');

        if ($this->year <= 0) {
            $this->year = (int) $now->year;
        }

        if ($this->month < 1 || $this->month > 12) {
            $this->month = (int) $now->month;
        }

        $this->spent_on = $now->toDateString();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'in:'.implode(',', array_keys(Expense::CATEGORIES))],
            'kind' => ['required', 'in:'.Expense::KIND_ONE_TIME.','.Expense::KIND_RECURRING],
            'spent_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Expense::query()->create([
            'title' => trim($validated['title']),
            'amount' => round((float) $validated['amount'], 2),
            'category' => $validated['category'],
            'kind' => $validated['kind'],
            'spent_on' => $validated['spent_on'],
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            'created_by' => auth()->id(),
        ]);

        $this->title = '';
        $this->amount = '';
        $this->category = 'other';
        $this->kind = Expense::KIND_ONE_TIME;
        $this->notes = '';
        $this->message = 'Expense saved.';
        $this->resetPage();
    }

    public function delete(int $expenseId): void
    {
        AdminAccess::ensureStaffAdmin();

        Expense::query()->whereKey($expenseId)->delete();
        $this->message = 'Expense deleted.';
    }

    /**
     * Copy last month's recurring expenses into the selected filter month.
     */
    public function duplicateLastMonthRecurring(): void
    {
        AdminAccess::ensureStaffAdmin();

        $target = Carbon::create($this->year, $this->month, 1, 0, 0, 0, 'Asia/Dhaka');
        $source = $target->copy()->subMonthNoOverflow();

        $sourceRows = Expense::query()
            ->where('kind', Expense::KIND_RECURRING)
            ->forMonth((int) $source->year, (int) $source->month)
            ->orderBy('id')
            ->get();

        if ($sourceRows->isEmpty()) {
            $this->message = 'No recurring expenses found in '.$source->format('F Y').'.';

            return;
        }

        $created = 0;

        foreach ($sourceRows as $row) {
            $alreadyExists = Expense::query()
                ->where('kind', Expense::KIND_RECURRING)
                ->where('title', $row->title)
                ->where('category', $row->category)
                ->forMonth($this->year, $this->month)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $day = min((int) $row->spent_on->day, (int) $target->copy()->endOfMonth()->day);

            Expense::query()->create([
                'title' => $row->title,
                'amount' => $row->amount,
                'category' => $row->category,
                'kind' => Expense::KIND_RECURRING,
                'spent_on' => $target->copy()->day($day)->toDateString(),
                'notes' => $row->notes,
                'created_by' => auth()->id(),
            ]);

            $created++;
        }

        $this->message = $created > 0
            ? "Copied {$created} recurring expense".($created === 1 ? '' : 's').' from '.$source->format('F Y').'.'
            : 'All recurring expenses from '.$source->format('F Y').' already exist in '.$target->format('F Y').'.';

        $this->resetPage();
    }

    public function render()
    {
        $expenses = Expense::query()
            ->with('creator:id,name')
            ->forMonth($this->year, $this->month)
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->paginate(30);

        $monthTotal = (float) Expense::query()
            ->forMonth($this->year, $this->month)
            ->sum('amount');

        return view('livewire.admin.admin-expenses', [
            'expenses' => $expenses,
            'monthTotal' => $monthTotal,
            'categories' => Expense::CATEGORIES,
        ]);
    }
}
