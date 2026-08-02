@php
    $unresolvedCount = (int) ($attentionSummary['unresolved_count'] ?? 0);
    $recentResolved = $attentionSummary['recent_resolved'] ?? collect();
    $hasAttentionItems = $unresolvedCount > 0;
@endphp

<div>
    <div class="mb-6 flex items-center justify-between gap-3">
        <h1 class="font-serif text-3xl font-semibold">Dashboard</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.orders.create') }}"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227] transition"
                aria-label="Create order"
                title="Create order">
                <span class="text-xl leading-none font-semibold">+</span>
            </a>
            <a href="{{ route('admin.inbox') }}"
                wire:navigate
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227] transition"
                aria-label="Open inbox"
                title="Open inbox">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                    <path d="M2.5 5.75A2.25 2.25 0 0 1 4.75 3.5h10.5A2.25 2.25 0 0 1 17.5 5.75v8.5a2.25 2.25 0 0 1-2.25 2.25H4.75A2.25 2.25 0 0 1 2.5 14.25v-8.5Zm2.68-.75a.75.75 0 0 0-.53 1.28l4.82 4.82a.75.75 0 0 0 1.06 0l4.82-4.82A.75.75 0 0 0 14.82 5H5.18Z" />
                </svg>
            </a>
        </div>
    </div>

    {{-- Admin Attention: compact when clear; expands only when something needs review. --}}
    @if ($hasAttentionItems)
        <div class="mb-6 rounded-xl border border-rose-200 bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rose-100 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-rose-900">Admin Attention</h2>
                    <p class="text-xs text-rose-700/80">{{ $unresolvedCount }} {{ $unresolvedCount === 1 ? 'issue needs' : 'issues need' }} review</p>
                </div>
                <a href="{{ route('admin.issues.index') }}"
                    class="shrink-0 text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                    View all &rarr;
                </a>
            </div>

            <div class="divide-y divide-[#EFE7D6]">
                @foreach ($attentionSummary['unresolved_items'] as $item)
                    <div class="flex items-start gap-3 px-4 py-3 hover:bg-[#FAF6EF]/50">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-blue-100 text-blue-800">
                                    {{ $item->getIssueTypeLabel() }}
                                </span>
                                @if ($item->order)
                                    <span class="text-xs text-[#6B6459]">Order #{{ $item->order->order_number }}</span>
                                @endif
                                <span class="text-[10px] text-[#8C8474]">{{ $item->created_at->diffForHumans() }}</span>
                            </div>
                            <h3 class="mt-1 truncate text-sm font-medium text-[#1E1E1E]">{{ $item->title }}</h3>
                            <p class="mt-0.5 line-clamp-2 text-xs text-[#8C8474]">{{ $item->description }}</p>
                            @if ($item->data && isset($item->data['expected_amount']) && isset($item->data['collected_amount']))
                                <p class="mt-1 text-xs font-medium text-rose-600">
                                    Expected: ৳{{ number_format($item->data['expected_amount'], 2) }}
                                    · Collected: ৳{{ number_format($item->data['collected_amount'], 2) }}
                                </p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col gap-1.5">
                            @if ($item->order)
                                <a href="{{ route('admin.orders.show', $item->order) }}"
                                    class="inline-flex items-center justify-center rounded px-2.5 py-1 text-xs font-medium text-white bg-[#C9A227] hover:bg-[#B8921F]">
                                    Review
                                </a>
                            @endif
                            <button type="button"
                                wire:click="markResolved({{ $item->id }})"
                                class="inline-flex items-center justify-center rounded border border-[#E7DFCF] px-2.5 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                                Resolve
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($unresolvedCount > 5)
                <div class="border-t border-[#EFE7D6] px-4 py-2.5 text-center">
                    <a href="{{ route('admin.issues.index') }}"
                        class="text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                        View all {{ $unresolvedCount }} issues &rarr;
                    </a>
                </div>
            @endif
        </div>
    @else
        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-[#EFE7D6] bg-white px-3 py-2">
            <p class="truncate text-xs text-[#8C8474]">
                <span class="font-medium text-[#6B6459]">Admin Attention</span>
                · All clear
                @if ($recentResolved->isNotEmpty())
                    · {{ $recentResolved->count() }} recently resolved
                @endif
            </p>
            <a href="{{ route('admin.issues.index') }}"
                class="shrink-0 text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                Issues &rarr;
            </a>
        </div>
    @endif

    @if ($expenseAssistantMessage)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ $expenseAssistantMessage }}</div>
    @endif

    @if (($dueExpenseReminders ?? collect())->isNotEmpty() || ($showEveningExpensePrompt ?? false))
        <div class="mb-6 rounded-xl border border-[#C9A227]/40 bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#EFE7D6] px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-[#1E1E1E]">Expense assistant</h2>
                    <p class="text-xs text-[#8C8474]">Asks from 2 days before each due date (short window) so the dashboard stays clear.</p>
                </div>
                <a href="{{ route('admin.expenses') }}" wire:navigate
                    class="shrink-0 text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                    Manage due dates →
                </a>
            </div>

            @if (($dueExpenseReminders ?? collect())->isNotEmpty())
                <div class="divide-y divide-[#EFE7D6]">
                    @foreach ($dueExpenseReminders as $reminder)
                        <div class="px-4 py-3" wire:key="expense-reminder-{{ $reminder->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-sm font-medium text-[#1E1E1E]">
                                            @if ($reminder->prompt_type === 'check')
                                                {{ $reminder->title }} checked?
                                            @else
                                                {{ $reminder->title }} paid?
                                            @endif
                                        </h3>
                                        <span class="rounded bg-[#FAF6EF] px-1.5 py-0.5 text-[10px] font-medium text-[#6B6459]">
                                            Due day {{ $reminder->due_day }}
                                        </span>
                                        <span class="rounded bg-[#FAF6EF] px-1.5 py-0.5 text-[10px] font-medium text-[#6B6459]">
                                            {{ $reminder->categoryLabel() }}
                                        </span>
                                    </div>
                                    @if ($reminder->notes)
                                        <p class="mt-1 text-xs text-[#8C8474]">{{ $reminder->notes }}</p>
                                    @endif
                                </div>
                                <button type="button"
                                    wire:click="skipExpenseReminder({{ $reminder->id }})"
                                    class="shrink-0 text-xs font-medium text-[#8C8474] hover:text-[#1E1E1E]">
                                    Skip this month
                                </button>
                            </div>

                            <div class="mt-3 flex flex-wrap items-end gap-2">
                                @if ($reminder->prompt_type === 'check')
                                    <button type="button"
                                        wire:click="markExpenseReminderChecked({{ $reminder->id }})"
                                        class="rounded-lg border border-[#E0D6C2] bg-[#FAF6EF] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:border-[#C9A227]">
                                        Checked — no top-up
                                    </button>
                                @endif
                                <div>
                                    <label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">
                                        {{ $reminder->prompt_type === 'check' ? 'Top-up amount' : 'Amount' }} (৳)
                                    </label>
                                    <input type="number" min="0.01" step="1"
                                        wire:model="expenseReminderAmounts.{{ $reminder->id }}"
                                        class="w-28 rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm tabular-nums focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                </div>
                                <button type="button"
                                    wire:click="recordExpenseReminder({{ $reminder->id }})"
                                    class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#B8921F]">
                                    {{ $reminder->prompt_type === 'check' ? 'Record top-up' : 'Yes, record it' }}
                                </button>
                                @error('expenseReminderAmounts.'.$reminder->id)
                                    <p class="w-full text-xs text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($showEveningExpensePrompt ?? false)
                <div @class([
                    'px-4 py-3',
                    'border-t border-[#EFE7D6]' => ($dueExpenseReminders ?? collect())->isNotEmpty(),
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-[#1E1E1E]">Any cost needs to be recorded?</h3>
                            <p class="mt-0.5 text-xs text-[#8C8474]">Shown between 8pm and 1am — one-off expenses from a busy day.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @unless ($showEveningExpenseForm)
                                <button type="button" wire:click="openEveningExpenseForm"
                                    class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#B8921F]">
                                    Yes
                                </button>
                                <button type="button" wire:click="dismissEveningExpensePrompt"
                                    class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Not tonight
                                </button>
                            @endunless
                        </div>
                    </div>

                    @if ($showEveningExpenseForm)
                        <form wire:submit="saveEveningExpense" class="mt-3 grid gap-2 sm:grid-cols-4">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">Title</label>
                                <input type="text" wire:model="eveningExpenseTitle" placeholder="e.g. Office supplies"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('eveningExpenseTitle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">Amount (৳)</label>
                                <input type="number" min="0.01" step="1" wire:model="eveningExpenseAmount"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm tabular-nums focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('eveningExpenseAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">Type</label>
                                <select wire:model="eveningExpenseCategory"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                    @foreach ($expenseCategories as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('eveningExpenseCategory') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-wrap items-end gap-2 sm:col-span-4">
                                <button type="submit"
                                    class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#B8921F]">
                                    Save expense
                                </button>
                                <button type="button" wire:click="dismissEveningExpensePrompt"
                                    class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Not tonight
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($courierChargeMessage)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ $courierChargeMessage }}</div>
    @endif

    @if ($returnHubMessage)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ $returnHubMessage }}</div>
    @endif

    @if (($returnHubArrivals ?? collect())->isNotEmpty())
        <div class="mb-6 rounded-xl border border-sky-200 bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sky-100 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-sky-950">Return parcels at Steadfast hub</h2>
                    <p class="text-xs text-sky-800/80">
                        {{ $returnHubArrivals->count() }}{{ $returnHubArrivals->count() >= 50 ? '+' : '' }}
                        Has Return {{ $returnHubArrivals->count() === 1 ? 'parcel has' : 'parcels have' }}
                        arrived at Steadfast Rampura hub — mark received (restores stock when return qty is set, clears H/R)
                    </p>
                </div>
                <a href="{{ route('admin.orders.return-pending') }}"
                    class="shrink-0 text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                    Return Pending &rarr;
                </a>
            </div>

            <div class="divide-y divide-[#EFE7D6]">
                @foreach ($returnHubArrivals as $order)
                    <div wire:key="return-hub-arrival-{{ $order->id }}" class="flex flex-wrap items-start gap-3 px-4 py-3 hover:bg-[#FAF6EF]/50">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                    class="truncate text-sm font-medium text-[#1E1E1E] hover:text-[#C9A227]">
                                    {{ $order->name }}
                                </a>
                                <span class="text-xs text-[#8C8474]">#{{ $order->order_number }}</span>
                                @if ($order->is_replacement)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-sky-100 text-sky-800">Exchange</span>
                                @endif
                                @if ($order->return_hub_arrived_at)
                                    <span class="text-[10px] text-[#8C8474]">{{ $order->return_hub_arrived_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            @if ($order->items->isNotEmpty())
                                @php
                                    $pendingReturnItems = $order->items->filter(fn ($item) => (int) $item->returned_quantity > 0 && ! $item->return_received);
                                    $hubItems = $pendingReturnItems->isNotEmpty() ? $pendingReturnItems : $order->items;
                                @endphp
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($hubItems as $item)
                                        @php
                                            $imageUrl = $item->imageUrl();
                                            $productName = $item->displayName();
                                            $badgeQty = (int) $item->returned_quantity > 0
                                                ? (int) $item->returned_quantity
                                                : (int) $item->quantity;
                                        @endphp
                                        <div wire:key="return-hub-item-{{ $item->id }}"
                                            title="{{ $productName }} ×{{ $badgeQty }}{{ (int) $item->returned_quantity > 0 ? ' returning' : '' }}"
                                            class="relative h-10 w-10 shrink-0 overflow-hidden rounded-md border border-[#E7DFCF] bg-[#FAF6EF]">
                                            @if ($imageUrl)
                                                <img src="{{ $imageUrl }}" alt="{{ $productName }}"
                                                    class="h-full w-full object-cover" loading="lazy">
                                            @else
                                                <span class="flex h-full w-full items-center justify-center text-[10px] text-[#8C8474]">?</span>
                                            @endif
                                            @if ($badgeQty > 1 || (int) $item->returned_quantity > 0)
                                                <span class="absolute bottom-0 right-0 rounded-tl bg-sky-700/85 px-1 text-[9px] font-semibold leading-4 text-white tabular-nums">
                                                    ×{{ $badgeQty }}
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col gap-1.5">
                            <button type="button"
                                wire:click="markReturnHubReceived({{ $order->id }})"
                                wire:confirm="Mark return received for order #{{ $order->order_number }}? Restores stock for return lines and clears H/R."
                                class="inline-flex items-center justify-center rounded px-2.5 py-1 text-xs font-medium text-white bg-sky-700 hover:bg-sky-800">
                                Mark as received
                            </button>
                            <a href="{{ route('admin.orders.return-pending') }}"
                                class="inline-flex items-center justify-center rounded border border-[#E7DFCF] px-2.5 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                                Open list
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (($unconfirmedCourierCharges ?? collect())->isNotEmpty())
        <div class="mb-6 rounded-xl border border-amber-200 bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-100 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-amber-950">Confirm courier charges</h2>
                    <p class="text-xs text-amber-800/80">
                        What the courier charges us (not customer delivery).
                        {{ $unconfirmedCourierCharges->count() }}{{ $unconfirmedCourierCharges->count() >= 25 ? '+' : '' }}
                        dispatched {{ $unconfirmedCourierCharges->count() === 1 ? 'order needs' : 'orders need' }}
                        charge + packaging review — packaging defaults 1→21 · 2→30 · 3+→41
                    </p>
                </div>
                <a href="{{ route('admin.orders.dispatched') }}"
                    class="shrink-0 text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">
                    Dispatched list &rarr;
                </a>
            </div>

            <div class="divide-y divide-[#EFE7D6]">
                @foreach ($unconfirmedCourierCharges as $order)
                    <div wire:key="confirm-courier-charge-{{ $order->id }}" class="space-y-2.5 px-4 py-3 hover:bg-[#FAF6EF]/50">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                class="min-w-0 truncate text-sm font-medium text-[#1E1E1E] hover:text-[#C9A227]">
                                {{ $order->name }}
                            </a>
                            @foreach (($courierChargeQuickAmounts[$order->id] ?? []) as $quickAmount)
                                <button type="button"
                                    wire:click="applyCourierChargePreset({{ $order->id }}, {{ $quickAmount }})"
                                    wire:key="courier-charge-preset-{{ $order->id }}-{{ $quickAmount }}"
                                    title="Set charge to ৳{{ $quickAmount }}"
                                    class="inline-flex h-5 min-w-5 items-center justify-center rounded-md border px-1 text-[10px] font-semibold tabular-nums transition
                                        {{ (string) ($pendingCourierCharges[$order->id] ?? '') === (string) $quickAmount
                                            ? 'border-amber-500 bg-amber-100 text-amber-900'
                                            : 'border-[#E0D6C2] bg-[#FAF6EF] text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]' }}">
                                    {{ $quickAmount }}
                                </button>
                            @endforeach
                            <span class="text-xs text-[#8C8474]">#{{ $order->order_number }}</span>
                        </div>

                        @if ($order->items->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($order->items as $item)
                                    @php
                                        $imageUrl = $item->imageUrl();
                                        $productName = $item->displayName();
                                    @endphp
                                    <div wire:key="confirm-charge-item-{{ $item->id }}"
                                        title="{{ $productName }} ×{{ $item->quantity }}"
                                        class="relative h-10 w-10 shrink-0 overflow-hidden rounded-md border border-[#E7DFCF] bg-[#FAF6EF]">
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="{{ $productName }}"
                                                class="h-full w-full object-cover" loading="lazy">
                                        @else
                                            <span class="flex h-full w-full items-center justify-center text-[10px] text-[#8C8474]">?</span>
                                        @endif
                                        @if ((int) $item->quantity > 1)
                                            <span class="absolute bottom-0 right-0 rounded-tl bg-black/65 px-1 text-[9px] font-semibold leading-4 text-white tabular-nums">
                                                ×{{ $item->quantity }}
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-[#8C8474]">
                            @php
                                $courierName = $order->courier?->name ?? 'No courier';
                            @endphp
                            @if ($steadfastUrl = $order->steadfastConsignmentUrl())
                                <a href="{{ $steadfastUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-medium text-[#C9A227] hover:underline"
                                    title="Open courier consignment">
                                    {{ $courierName }} ↗
                                </a>
                            @else
                                <span class="font-medium text-[#6B6459]">{{ $courierName }}</span>
                            @endif
                            @if ($order->courier_tracker)
                                <span>· {{ $order->courier_tracker }}</span>
                            @endif
                            @if ($order->city)
                                <span>· {{ $order->city }}</span>
                            @endif
                            @if ($order->dispatch_date)
                                <span>· {{ $order->dispatch_date->format('d M, h:i A') }}</span>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[7rem] flex-1 basis-28 sm:max-w-[9rem] sm:flex-none">
                                <label for="courier-charge-{{ $order->id }}" class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">
                                    Courier ৳
                                    <span class="normal-case tracking-normal font-normal">
                                        · {{ $courierChargeAreaLabels[$order->id] ?? 'Outside Dhaka' }}
                                    </span>
                                </label>
                                <input id="courier-charge-{{ $order->id }}"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    wire:model="pendingCourierCharges.{{ $order->id }}"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm tabular-nums">
                                @error('pendingCourierCharges.'.$order->id)
                                    <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="w-24 shrink-0">
                                <label for="packaging-cost-{{ $order->id }}" class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">
                                    Pack ৳
                                </label>
                                <input id="packaging-cost-{{ $order->id }}"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    wire:model="pendingPackagingCosts.{{ $order->id }}"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-2.5 py-1.5 text-sm tabular-nums">
                                @error('pendingPackagingCosts.'.$order->id)
                                    <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="button"
                                wire:click="confirmCourierCharge({{ $order->id }})"
                                wire:loading.attr="disabled"
                                wire:target="confirmCourierCharge({{ $order->id }})"
                                class="rounded-full bg-[#C9A227] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                <span wire:loading.remove wire:target="confirmCourierCharge({{ $order->id }})">Update</span>
                                <span wire:loading wire:target="confirmCourierCharge({{ $order->id }})">Saving…</span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @php
        $primaryKeys = ['new', 'draft-ai', 'dispatched'];
        $primarySegments = collect($segments)->only($primaryKeys);
        $secondarySegments = collect($segments)->except($primaryKeys);
    @endphp

    <div x-data="{ moreOpen: false }" class="mb-8 space-y-3">
        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            @foreach ($primarySegments as $segmentKey => $segmentLabel)
                @php
                    $showValue = in_array($segmentKey, \App\Support\AdminOrderSegment::VALUE_SEGMENTS, true);
                    $segmentValue = (float) ($segmentValues[$segmentKey] ?? 0);
                @endphp
                <a href="{{ route('admin.orders.'.$segmentKey) }}"
                    class="flex min-w-0 items-center justify-between gap-2 rounded-xl border border-[#EFE7D6] bg-white px-3 py-2.5 sm:px-4 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group"
                    title="{{ $segmentLabel }}{{ $showValue ? ' · ৳'.number_format($segmentValue, 0) : '' }}">
                    <span class="truncate text-xs sm:text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</span>
                    <span class="shrink-0 text-right">
                        <span class="block text-lg sm:text-xl font-semibold tabular-nums text-[#1E1E1E] leading-none">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</span>
                        @if ($showValue)
                            <span class="mt-0.5 block text-[10px] sm:text-xs font-medium tabular-nums text-[#8C8474]">৳{{ number_format($segmentValue, 0) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>

        @if ($secondarySegments->isNotEmpty())
            <div class="flex justify-center">
                <button type="button"
                    @click="moreOpen = ! moreOpen"
                    :aria-expanded="moreOpen.toString()"
                    aria-controls="dashboard-more-segments"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227] transition"
                    :title="moreOpen ? 'Hide other segments' : 'Show other segments'"
                    :aria-label="moreOpen ? 'Hide other segments' : 'Show other segments'">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"
                        class="transition-transform duration-200"
                        :class="moreOpen ? 'rotate-180' : ''">
                        <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
                    </svg>
                </button>
            </div>

            <div id="dashboard-more-segments"
                x-show="moreOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                class="grid grid-cols-2 xl:grid-cols-4 gap-2 sm:gap-3">
                @foreach ($secondarySegments as $segmentKey => $segmentLabel)
                    <a href="{{ route('admin.orders.'.$segmentKey) }}"
                        class="flex min-w-0 items-center justify-between gap-2 rounded-xl border border-[#EFE7D6] bg-white px-3 py-2.5 sm:px-4 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group"
                        title="{{ $segmentLabel }}">
                        <span class="truncate text-xs sm:text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</span>
                        <span class="shrink-0 text-lg sm:text-xl font-semibold tabular-nums text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="font-semibold text-base sm:text-lg">Orders</h2>
                <p class="mt-0.5 text-xs text-[#8C8474]">
                    Month tiles for totals · day table for a quick status check. DQ = of that day's orders, how many later became delivered (even on a later date). CV = money received on those.
                </p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($orderMonthTiles as $tile)
                @php
                    $isActive = $ordersDateRange === $tile['range'];
                @endphp
                <button type="button"
                    wire:click="showOrdersDateRange('{{ $tile['range'] }}')"
                    wire:key="order-month-tile-{{ $tile['key'] }}"
                    @class([
                        'rounded-xl border px-4 py-3 text-left transition',
                        'border-[#C9A227] bg-[#FAF6EF] ring-1 ring-[#C9A227]/30' => $isActive,
                        'border-[#EFE7D6] bg-white hover:border-[#C9A227] hover:bg-[#FAF6EF]/50' => ! $isActive,
                    ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-[#1E1E1E]">{{ $tile['label'] }}</span>
                        <span class="text-[10px] font-medium text-[#C9A227]">
                            {{ $isActive ? 'Showing days' : 'Show by date →' }}
                        </span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                        <div>
                            <p class="text-[#8C8474]">Ordered</p>
                            <p class="font-semibold tabular-nums text-[#1E1E1E]">
                                {{ number_format($tile['totals']['order_qty']) }}
                                · ৳{{ number_format($tile['totals']['order_value'], 0) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[#8C8474]">Delivered</p>
                            <p class="font-semibold tabular-nums text-[#1E1E1E]">
                                {{ number_format($tile['totals']['delivery_qty']) }}
                                · ৳{{ number_format($tile['totals']['delivery_value'], 0) }}
                            </p>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#E7DFCF] px-3 py-3 sm:px-4">
                <div>
                    <h3 class="text-sm font-semibold text-[#1E1E1E]">Orders by date</h3>
                    <p class="mt-0.5 text-xs text-[#8C8474]">{{ $ordersDatePanel['label'] }}</p>
                </div>
                @if ($ordersDateRange !== 'last7')
                    <button type="button" wire:click="showOrdersDateRange('last7')"
                        class="rounded-full border border-[#E0D6C2] px-3 py-1 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Back to last 7 days
                    </button>
                @else
                    <span class="rounded-full bg-[#FAF6EF] px-3 py-1 text-[10px] font-medium uppercase tracking-wide text-[#8C8474]">
                        Default view
                    </span>
                @endif
            </div>

            <div>
                <table class="w-full table-fixed text-xs sm:text-sm">
                    <colgroup>
                        <col class="w-[18%]">
                        <col class="w-[20.5%]">
                        <col class="w-[20.5%]">
                        <col class="w-[20.5%]">
                        <col class="w-[20.5%]">
                    </colgroup>
                    <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                        <tr>
                            <th class="px-1.5 py-2 sm:px-2 font-medium">Date</th>
                            @foreach ([
                                ['abbr' => 'OQ', 'label' => 'Orders placed that day'],
                                ['abbr' => 'OV', 'label' => 'Value of orders placed that day'],
                                ['abbr' => 'DQ', 'label' => 'Of those orders, how many later became delivered (even on a later date)'],
                                ['abbr' => 'CV', 'label' => 'Money received on those delivered orders'],
                            ] as $column)
                                <th class="px-1 py-2 sm:px-2 font-medium text-right" scope="col">
                                    <span
                                        x-data="{ open: false }"
                                        tabindex="0"
                                        role="button"
                                        class="relative inline-flex cursor-help rounded outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227]/60"
                                        :aria-expanded="open"
                                        aria-label="{{ $column['label'] }}"
                                        title="{{ $column['label'] }}"
                                        @mouseenter="open = true"
                                        @mouseleave="open = false"
                                        @focus="open = true"
                                        @blur="open = false"
                                        @keydown.space.prevent="open = ! open"
                                        @keydown.enter.prevent="open = ! open"
                                        @keydown.escape="open = false"
                                    >
                                        {{ $column['abbr'] }}
                                        <span
                                            x-cloak
                                            x-show="open"
                                            x-transition.opacity.duration.150ms
                                            role="tooltip"
                                            class="pointer-events-none absolute right-0 top-full z-20 mt-1 whitespace-nowrap rounded-md bg-[#1E1E1E] px-2 py-1 text-xs font-normal normal-case tracking-normal text-white shadow-sm"
                                        >{{ $column['label'] }}</span>
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E7DFCF]">
                        @forelse ($ordersDatePanel['days'] as $day)
                            <tr class="hover:bg-[#FAF6EF]/50" wire:key="orders-day-{{ $ordersDatePanel['key'] }}-{{ $day['date'] }}">
                                <td class="px-1.5 py-1.5 sm:px-2 font-medium tabular-nums">{{ $day['label'] }}</td>
                                <td class="px-1 py-1.5 sm:px-2 text-right tabular-nums">
                                    <a href="{{ route('admin.orders.all', ['dateFrom' => $day['date'], 'dateTo' => $day['date']]) }}"
                                        class="underline decoration-[#E0D6C2] underline-offset-2 hover:text-[#C9A227]"
                                        title="Orders placed on {{ $day['label'] }}">{{ number_format($day['order_qty']) }}</a>
                                </td>
                                <td class="px-1 py-1.5 sm:px-2 text-right tabular-nums">{{ number_format($day['order_value'], 0) }}</td>
                                <td class="px-1 py-1.5 sm:px-2 text-right tabular-nums">
                                    <a href="{{ route('admin.orders.delivered', ['dateFrom' => $day['date'], 'dateTo' => $day['date']]) }}"
                                        class="underline decoration-[#E0D6C2] underline-offset-2 hover:text-[#C9A227]"
                                        title="Of {{ $day['label'] }} orders, later delivered">{{ number_format($day['delivery_qty']) }}</a>
                                </td>
                                <td class="px-1 py-1.5 sm:px-2 text-right tabular-nums">{{ number_format($day['delivery_value'], 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-8 text-center text-[#8C8474]">No orders in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (($ordersDatePanel['days'] ?? []) !== [])
                        <tfoot class="bg-[#FAF6EF] font-semibold border-t border-[#E7DFCF]">
                            <tr>
                                <td class="px-1.5 py-2 sm:px-2">Total</td>
                                <td class="px-1 py-2 sm:px-2 text-right tabular-nums">{{ number_format($ordersDatePanel['totals']['order_qty']) }}</td>
                                <td class="px-1 py-2 sm:px-2 text-right tabular-nums">{{ number_format($ordersDatePanel['totals']['order_value'], 0) }}</td>
                                <td class="px-1 py-2 sm:px-2 text-right tabular-nums">{{ number_format($ordersDatePanel['totals']['delivery_qty']) }}</td>
                                <td class="px-1 py-2 sm:px-2 text-right tabular-nums">{{ number_format($ordersDatePanel['totals']['delivery_value'], 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
