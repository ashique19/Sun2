<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Expenses</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Indirect business costs (salary, rent, ads…). Monthly total feeds Analytics → Indirect.
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Year</label>
                <select wire:model.live="year"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm">
                    @for ($y = now('Asia/Dhaka')->year; $y >= now('Asia/Dhaka')->year - 5; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Month</label>
                <select wire:model.live="month"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm">
                    @for ($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->format('M') }}</option>
                    @endfor
                </select>
            </div>
            <button type="button" wire:click="duplicateLastMonthRecurring"
                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                Copy last month’s recurring
            </button>
        </div>
    </div>

    @if ($message)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ $message }}</div>
    @endif

    <div class="mb-6 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <h2 class="mb-4 text-sm font-semibold">Add expense</h2>
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="sm:col-span-2 lg:col-span-1">
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Title</label>
                <input type="text" wire:model="title" placeholder="e.g. Office rent"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                @error('title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Amount (&#2547;)</label>
                <input type="number" min="0.01" step="0.01" wire:model="amount"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                @error('amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Date</label>
                <input type="date" wire:model="spent_on"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                @error('spent_on') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Category</label>
                <select wire:model="category" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('category') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Kind</label>
                <select wire:model="kind" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                    <option value="one_time">One-time</option>
                    <option value="recurring">Recurring</option>
                </select>
                @error('kind') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Notes (optional)</label>
                <input type="text" wire:model="notes" placeholder="Optional"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                @error('notes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end sm:col-span-2 lg:col-span-3">
                <button type="submit"
                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                    Save expense
                </button>
            </div>
        </form>
    </div>

    <div class="mb-6 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold">Monthly reminders</h2>
                <p class="mt-1 text-xs text-[#8C8474]">
                    Due days are editable. The dashboard asks from 2 days before the due day through 2 days after, until you record, check, or skip.
                </p>
            </div>
        </div>

        <div class="mb-5 overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead class="text-left text-[#8C8474]">
                    <tr class="border-b border-[#EFE7D6]">
                        <th class="py-2 pr-3 font-medium">Title</th>
                        <th class="py-2 pr-3 font-medium">Due day</th>
                        <th class="py-2 pr-3 font-medium">Type</th>
                        <th class="py-2 pr-3 font-medium">Category</th>
                        <th class="py-2 pr-3 font-medium text-right">Default ৳</th>
                        <th class="py-2 font-medium text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F0EBE0]">
                    @forelse ($reminders as $reminder)
                        <tr wire:key="reminder-row-{{ $reminder->id }}" @class(['opacity-50' => ! $reminder->is_active])>
                            <td class="py-2 pr-3">
                                <div class="font-medium text-[#1E1E1E]">{{ $reminder->title }}</div>
                                @if ($reminder->notes)
                                    <div class="text-xs text-[#8C8474]">{{ $reminder->notes }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-3 tabular-nums">{{ $reminder->due_day }}</td>
                            <td class="py-2 pr-3">{{ $reminder->promptTypeLabel() }}</td>
                            <td class="py-2 pr-3">{{ $reminder->categoryLabel() }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ $reminder->default_amount !== null ? '৳ '.number_format((float) $reminder->default_amount, 0) : '—' }}
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button type="button" wire:click="editReminder({{ $reminder->id }})"
                                    class="text-xs font-medium text-[#C9A227] hover:underline">Edit</button>
                                <button type="button" wire:click="deleteReminder({{ $reminder->id }})"
                                    wire:confirm="Delete reminder “{{ $reminder->title }}”?"
                                    class="ml-2 text-xs text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-[#8C8474]">No reminders yet. Add one below.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form wire:submit="saveReminder" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 border-t border-[#EFE7D6] pt-4">
            <div class="sm:col-span-2 lg:col-span-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[#8C8474]">
                    {{ $editingReminderId ? 'Edit reminder' : 'Add reminder' }}
                </h3>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Title</label>
                <input type="text" wire:model="reminderTitle" placeholder="e.g. Salary"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                @error('reminderTitle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Due day of month</label>
                <input type="number" min="1" max="28" wire:model="reminderDueDay"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                @error('reminderDueDay') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Prompt</label>
                <select wire:model="reminderPromptType" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                    @foreach ($promptTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('reminderPromptType') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Category</label>
                <select wire:model="reminderCategory" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('reminderCategory') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Default amount (optional)</label>
                <input type="number" min="0.01" step="0.01" wire:model="reminderDefaultAmount"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                @error('reminderDefaultAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Notes (optional)</label>
                <input type="text" wire:model="reminderNotes" placeholder="e.g. Pay by next working day"
                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                @error('reminderNotes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-3">
                <label class="inline-flex items-center gap-2 text-sm text-[#6B6459]">
                    <input type="checkbox" wire:model="reminderIsActive" class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                    Active on dashboard
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-3">
                <button type="submit"
                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                    {{ $editingReminderId ? 'Update reminder' : 'Add reminder' }}
                </button>
                @if ($editingReminderId)
                    <button type="button" wire:click="cancelReminderEdit"
                        class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                @endif
            </div>
        </form>
    </div>

    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold">
            {{ \Carbon\Carbon::create($year, $month, 1)->format('F Y') }}
        </h2>
        <p class="text-sm text-[#6B6459]">
            Month total:
            <span class="font-semibold tabular-nums text-[#1E1E1E]">&#2547; {{ number_format($monthTotal, 0) }}</span>
        </p>
    </div>

    <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Title</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Kind</th>
                        <th class="px-4 py-3 font-medium text-right">Amount</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($expenses as $expense)
                        <tr class="hover:bg-[#FAF6EF]/50" wire:key="expense-{{ $expense->id }}">
                            <td class="px-4 py-3 whitespace-nowrap text-[#8C8474]">
                                {{ $expense->spent_on?->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $expense->title }}</div>
                                @if ($expense->notes)
                                    <div class="text-xs text-[#8C8474]">{{ $expense->notes }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $expense->categoryLabel() }}</td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $expense->kindLabel() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">
                                &#2547; {{ number_format((float) $expense->amount, 0) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button"
                                    wire:click="delete({{ $expense->id }})"
                                    wire:confirm="Delete “{{ $expense->title }}”?"
                                    class="text-xs text-rose-600 hover:underline">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-[#8C8474]">
                                No expenses this month. Add one above, or copy last month’s recurring.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($expenses->hasPages())
            <div class="border-t border-[#EFE7D6] px-4 py-3">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>
</div>
