<?php

namespace App\Livewire\Admin;

use App\Models\Expense;
use App\Models\ExpenseRecurringReminder;
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

    public ?int $editingReminderId = null;

    public string $reminderTitle = '';

    public string $reminderCategory = 'other';

    public string $reminderDefaultAmount = '';

    public string $reminderDueDay = '1';

    public string $reminderPromptType = ExpenseRecurringReminder::PROMPT_PAYMENT;

    public string $reminderNotes = '';

    public bool $reminderIsActive = true;

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

    public function editReminder(int $reminderId): void
    {
        AdminAccess::ensureStaffAdmin();

        $reminder = ExpenseRecurringReminder::query()->findOrFail($reminderId);

        $this->editingReminderId = $reminder->id;
        $this->reminderTitle = $reminder->title;
        $this->reminderCategory = $reminder->category;
        $this->reminderDefaultAmount = $reminder->default_amount !== null
            ? (string) (int) round((float) $reminder->default_amount)
            : '';
        $this->reminderDueDay = (string) $reminder->due_day;
        $this->reminderPromptType = $reminder->prompt_type;
        $this->reminderNotes = (string) ($reminder->notes ?? '');
        $this->reminderIsActive = (bool) $reminder->is_active;
    }

    public function cancelReminderEdit(): void
    {
        $this->resetReminderForm();
    }

    public function saveReminder(): void
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $this->validate([
            'reminderTitle' => ['required', 'string', 'max:160'],
            'reminderCategory' => ['required', 'in:'.implode(',', array_keys(Expense::CATEGORIES))],
            'reminderDefaultAmount' => ['nullable', 'numeric', 'min:0.01'],
            'reminderDueDay' => ['required', 'integer', 'min:1', 'max:28'],
            'reminderPromptType' => ['required', 'in:'.implode(',', array_keys(ExpenseRecurringReminder::PROMPT_TYPES))],
            'reminderNotes' => ['nullable', 'string', 'max:1000'],
            'reminderIsActive' => ['boolean'],
        ], [], [
            'reminderTitle' => 'title',
            'reminderCategory' => 'category',
            'reminderDefaultAmount' => 'default amount',
            'reminderDueDay' => 'due day',
            'reminderPromptType' => 'prompt type',
            'reminderNotes' => 'notes',
        ]);

        $payload = [
            'title' => trim($validated['reminderTitle']),
            'category' => $validated['reminderCategory'],
            'default_amount' => filled($validated['reminderDefaultAmount'] ?? null)
                ? round((float) $validated['reminderDefaultAmount'], 2)
                : null,
            'due_day' => (int) $validated['reminderDueDay'],
            'prompt_type' => $validated['reminderPromptType'],
            'notes' => filled($validated['reminderNotes'] ?? null) ? trim((string) $validated['reminderNotes']) : null,
            'is_active' => (bool) $validated['reminderIsActive'],
        ];

        if ($this->editingReminderId) {
            ExpenseRecurringReminder::query()->whereKey($this->editingReminderId)->update($payload);
            $this->message = 'Reminder updated.';
        } else {
            $maxSort = (int) ExpenseRecurringReminder::query()->max('sort_order');
            ExpenseRecurringReminder::query()->create([
                ...$payload,
                'sort_order' => $maxSort + 10,
            ]);
            $this->message = 'Reminder added.';
        }

        $this->resetReminderForm();
    }

    public function deleteReminder(int $reminderId): void
    {
        AdminAccess::ensureStaffAdmin();

        ExpenseRecurringReminder::query()->whereKey($reminderId)->delete();

        if ($this->editingReminderId === $reminderId) {
            $this->resetReminderForm();
        }

        $this->message = 'Reminder deleted.';
    }

    private function resetReminderForm(): void
    {
        $this->editingReminderId = null;
        $this->reminderTitle = '';
        $this->reminderCategory = 'other';
        $this->reminderDefaultAmount = '';
        $this->reminderDueDay = '1';
        $this->reminderPromptType = ExpenseRecurringReminder::PROMPT_PAYMENT;
        $this->reminderNotes = '';
        $this->reminderIsActive = true;
        $this->resetValidation([
            'reminderTitle',
            'reminderCategory',
            'reminderDefaultAmount',
            'reminderDueDay',
            'reminderPromptType',
            'reminderNotes',
            'reminderIsActive',
        ]);
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

        $reminders = ExpenseRecurringReminder::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('livewire.admin.admin-expenses', [
            'expenses' => $expenses,
            'monthTotal' => $monthTotal,
            'categories' => Expense::CATEGORIES,
            'reminders' => $reminders,
            'promptTypes' => ExpenseRecurringReminder::PROMPT_TYPES,
        ]);
    }
}
