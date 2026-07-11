<div wire:key="admin-orders-{{ $segment }}-{{ $listRevision }}" @class(['pb-14' => ! $readOnly && $selectedCount > 0])>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 class="font-serif text-3xl font-semibold">{{ $segmentLabel }} Orders</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($segment === 'dispatched' && $courierApiAvailable && ! $readOnly)
                <button type="button"
                    wire:click="refreshCourierStatuses"
                    wire:loading.attr="disabled"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF] disabled:opacity-60">
                    <span wire:loading.remove wire:target="refreshCourierStatuses">Refresh tracking</span>
                    <span wire:loading wire:target="refreshCourierStatuses">Refreshing…</span>
                </button>
            @endif
            @unless ($readOnly)
                <a href="{{ route('admin.orders.create') }}"
                    class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b89220] transition">
                    Create order
                </a>
            @endunless
        </div>
    </div>

    @unless ($readOnly)
        <div class="flex flex-wrap gap-2 mb-6">
            @foreach ($segments as $segmentKey => $segmentName)
                <button type="button"
                    wire:key="segment-tab-{{ $segmentKey }}"
                    wire:click="switchSegment('{{ $segmentKey }}')"
                    wire:loading.attr="disabled"
                    class="rounded-full px-4 py-1.5 text-sm border transition disabled:opacity-60 {{ $segment === $segmentKey ? 'border-[#C9A227] bg-[#C9A227] text-white font-medium' : 'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                    {{ $segmentName }}
                </button>
            @endforeach
        </div>
    @endunless

    @unless ($readOnly)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search order #, name, phone…"
                class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        </div>
    @endunless

    @if ($readOnly)
        <div class="space-y-3" wire:loading.class="opacity-60" wire:target="switchSegment,search,nextPage,previousPage,gotoPage" wire:key="moderator-orders-{{ $segment }}-{{ $listRevision }}">
            @forelse ($orders as $order)
                @php($adminNote = filled($order->admin_note) ? \Illuminate\Support\Str::of($order->admin_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                @php($courierNote = filled($order->courier_note) ? \Illuminate\Support\Str::of($order->courier_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                <article wire:key="order-card-{{ $order->id }}" class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="font-medium text-[#1E1E1E]">{{ $order->name }}</div>
                            <div class="text-sm text-[#8C8474]">{{ $order->phone }}</div>
                            @if (filled($order->address))
                                <p class="mt-1 text-sm leading-relaxed text-[#6B6459] break-words">
                                    {{ $order->address }}
                                    @if (filled($order->area) || filled($order->city))
                                        <span class="text-[#8C8474]">
                                            — {{ collect([$order->area, $order->city])->filter()->implode(', ') }}
                                        </span>
                                    @endif
                                </p>
                            @endif
                            @if ($order->is_replacement)
                                <span title="Exchange order"
                                    class="mt-1 inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                    Exc
                                </span>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-start gap-4">
                            @forelse ($order->items as $item)
                                <x-order-product-thumb
                                    :item="$item"
                                    size="md"
                                    show-quantity
                                />
                            @empty
                                <span class="text-sm text-[#8C8474]">—</span>
                            @endforelse
                        </div>
                    </div>
                    @if ($adminNote || $courierNote)
                        <div class="mt-3 space-y-2 border-t border-[#EFE7D6] pt-3">
                            @if ($adminNote)
                                <div class="rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Admin</p>
                                    <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $adminNote }}</p>
                                </div>
                            @endif
                            @if ($courierNote)
                                <div class="rounded-lg border border-[#E7DFCF] bg-[#FAF6EF]/60 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Courier</p>
                                    <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $courierNote }}</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-[#8C8474]">No orders found.</div>
            @endforelse

            @if ($orders->hasPages())
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">{{ $orders->links() }}</div>
            @endif
        </div>
    @else
    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden" wire:loading.class="opacity-60" wire:target="switchSegment,search,nextPage,previousPage,gotoPage">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        @unless ($readOnly)
                            <th class="w-12 px-4 py-3 font-medium">
                                <label class="inline-flex items-center gap-2 cursor-pointer" title="Select all on this page">
                                    <input type="checkbox"
                                        wire:key="select-all-{{ $isPageFullySelected ? 'on' : 'off' }}"
                                        wire:click.prevent="togglePageSelection"
                                        @checked($isPageFullySelected)
                                        @disabled($orders->isEmpty())
                                        class="rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227] disabled:opacity-40">
                                    <span class="sr-only">Select all on this page</span>
                                </label>
                            </th>
                            <th class="px-4 py-3 font-medium">Order</th>
                        @endunless
                        <th class="px-4 py-3 font-medium">Customer</th>
                        <th class="px-4 py-3 font-medium">Products</th>
                        @unless ($readOnly)
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Placed</th>
                            <th class="px-4 py-3 font-medium"></th>
                        @endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]" wire:key="orders-tbody-{{ $segment }}-{{ $listRevision }}">
                    @forelse ($orders as $order)
                        @php($isSelected = in_array($order->id, $selectedIds, true))
                        @php($adminNote = filled($order->admin_note) ? \Illuminate\Support\Str::of($order->admin_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                        @php($courierNote = filled($order->courier_note) ? \Illuminate\Support\Str::of($order->courier_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                        <tr wire:key="order-row-{{ $order->id }}" @class(['hover:bg-[#FAF6EF]/60', 'bg-[#FAF6EF]/80' => ! $readOnly && $isSelected])>
                            @unless ($readOnly)
                                <td class="px-4 py-3 align-top">
                                    <input type="checkbox"
                                        wire:key="order-select-{{ $order->id }}-{{ $isSelected ? 'on' : 'off' }}"
                                        wire:click.prevent="toggleOrder({{ $order->id }})"
                                        @checked($isSelected)
                                        class="rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">
                                            #{{ $order->order_number }}
                                        </a>
                                        @if ($order->is_replacement)
                                            <span title="Exchange order"
                                                class="inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                                Exc
                                            </span>
                                        @endif
                                    </div>
                                    @if ($adminNote)
                                        <p class="mt-1 text-xs text-[#6B6459] whitespace-pre-line max-w-[14rem]">
                                            <span class="font-medium text-[#8C8474]">Admin:</span>
                                            {{ $adminNote }}
                                        </p>
                                    @endif
                                    @if ($courierNote)
                                        <p class="mt-1 text-xs text-[#6B6459] whitespace-pre-line max-w-[14rem]">
                                            <span class="font-medium text-[#8C8474]">Courier:</span>
                                            {{ $courierNote }}
                                        </p>
                                    @endif
                                </td>
                            @endunless
                            <td class="px-4 py-3 cursor-pointer {{ $readOnly ? 'align-middle' : '' }}"
                                role="button"
                                tabindex="0"
                                title="Click to copy phone"
                                data-phone="{{ $order->phone }}"
                                onclick="window.sunCopyText(this.dataset.phone, this.querySelector('[data-copy-feedback]'))"
                                onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
                                <div class="font-medium text-[#1E1E1E]">{{ $order->name }}</div>
                                <div class="flex items-center gap-1.5 text-[#8C8474]">
                                    <span>{{ $order->phone }}</span>
                                    <span data-copy-feedback class="hidden text-[10px] font-semibold uppercase text-emerald-600">Copied</span>
                                </div>
                                @php($customerAddress = collect([$order->address, $order->area, $order->city])->filter()->implode(', '))
                                @if ($customerAddress !== '')
                                    <p class="mt-0.5 text-xs leading-snug text-[#8C8474] max-w-[14rem] break-words">{{ $customerAddress }}</p>
                                @endif
                                @if ($readOnly && $order->is_replacement)
                                    <span title="Exchange order"
                                        class="mt-1 inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                        Exc
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 {{ $readOnly ? 'align-middle' : '' }}">
                                @if ($order->items->isEmpty())
                                    <span class="text-[#8C8474]">—</span>
                                @else
                                    <div @class(['flex flex-wrap items-start', 'gap-4' => $readOnly, 'gap-3' => ! $readOnly])>
                                        @foreach ($order->items as $item)
                                            <x-order-product-thumb
                                                :item="$item"
                                                :size="$readOnly ? 'md' : 'sm'"
                                                show-quantity
                                                :show-return="! $readOnly && $segment === 'return-pending'"
                                            />
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            @unless ($readOnly)
                                <td class="px-4 py-3">&#2547; {{ number_format($order->total, 0) }}</td>
                                <td class="px-4 py-3 capitalize">{{ $order->status }}</td>
                                <td class="px-4 py-3 text-[#6B6459]">{{ $order->placed_at?->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-2">
                                        @if ($segment === 'new')
                                            <button type="button"
                                                wire:click="quickDispatch({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="quickDispatch({{ $order->id }})"
                                                title="Dispatch via default courier"
                                                aria-label="Dispatch order #{{ $order->order_number }}"
                                                class="inline-flex h-6 min-w-6 items-center justify-center rounded border border-[#E0D6C2] bg-white px-1.5 text-xs font-semibold text-[#1E1E1E] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60">
                                                <span wire:loading.remove wire:target="quickDispatch({{ $order->id }})">D</span>
                                                <svg wire:loading wire:target="quickDispatch({{ $order->id }})"
                                                    class="h-3.5 w-3.5 animate-spin text-[#C9A227]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </button>
                                        @endif
                                        @if ($segment === 'dispatched')
                                            <button type="button"
                                                wire:click="markDelivered({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markDelivered({{ $order->id }})"
                                                title="Mark delivered"
                                                class="inline-flex h-6 items-center justify-center rounded border border-emerald-200 bg-white px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60">
                                                Delv
                                            </button>
                                            <button type="button"
                                                wire:click="openPartialReturn({{ $order->id }})"
                                                title="Partial return"
                                                class="inline-flex h-6 items-center justify-center rounded border border-[#E0D6C2] bg-white px-1.5 text-xs font-semibold text-[#6B6459] hover:bg-[#FAF6EF]">
                                                Partial
                                            </button>
                                            <button type="button"
                                                wire:click="cancelAndReturn({{ $order->id }})"
                                                wire:confirm="Cancel &amp; return order #{{ $order->order_number }} with no delivery charge?"
                                                title="Cancel and return (no delivery charge)"
                                                class="inline-flex h-6 items-center justify-center rounded border border-rose-200 bg-white px-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                C/R
                                            </button>
                                        @endif
                                        @if ($segment === 'return-pending')
                                            @php($hasPendingReturn = $order->items->contains(fn ($item) => (int) $item->returned_quantity > 0 && ! $item->return_received))
                                            @php($hasReceivedReturn = $order->items->contains(fn ($item) => (bool) $item->return_received))
                                            <button type="button"
                                                wire:click="markReturnReceived({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markReturnReceived({{ $order->id }})"
                                                @disabled(! $hasPendingReturn)
                                                title="Mark return received"
                                                class="inline-flex h-6 items-center justify-center rounded border border-emerald-200 bg-white px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40">
                                                Recv
                                            </button>
                                            <button type="button"
                                                wire:click="undoReturnReceived({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="undoReturnReceived({{ $order->id }})"
                                                @disabled(! $hasReceivedReturn)
                                                title="Undo return received"
                                                class="inline-flex h-6 items-center justify-center rounded border border-amber-200 bg-white px-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-40">
                                                Undo
                                            </button>
                                            <button type="button"
                                                wire:click="toggleHasReturn({{ $order->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="toggleHasReturn({{ $order->id }})"
                                                title="{{ $order->has_return ? 'Clear has return (H/R off)' : 'Set has return (H/R on)' }}"
                                                @class([
                                                    'inline-flex h-6 items-center justify-center rounded border px-1.5 text-xs font-semibold disabled:opacity-60',
                                                    'border-[#C9A227] bg-[#C9A227] text-white' => $order->has_return,
                                                    'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' => ! $order->has_return,
                                                ])>
                                                H/R
                                            </button>
                                        @endif
                                        <a href="{{ route('admin.orders.print', $order) }}" target="_blank"
                                            title="Print label"
                                            aria-label="Print label"
                                            class="inline-flex items-center opacity-70 hover:opacity-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">
                                                <path fill="#6B6459" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
                                            </svg>
                                        </a>
                                        <a href="{{ route('admin.orders.create', ['repeat' => $order->id]) }}"
                                            title="Repeat order"
                                            aria-label="Repeat order"
                                            class="inline-flex items-center opacity-70 hover:opacity-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">
                                                <path fill="#6B6459" d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>
                                            </svg>
                                        </a>
                                        <button type="button"
                                            wire:click="deleteOrder({{ $order->id }})"
                                            wire:confirm="Delete order #{{ $order->order_number }} and restore product stock?"
                                            title="Delete order"
                                            aria-label="Delete order"
                                            class="inline-flex items-center opacity-70 hover:opacity-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">
                                                <path fill="#B91C1C" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            @endunless
                        </tr>
                        @if ($readOnly && ($adminNote || $courierNote))
                            <tr wire:key="order-notes-{{ $order->id }}">
                                <td colspan="2" class="px-4 pb-4 pt-0">
                                    <div class="space-y-2">
                                        @if ($adminNote)
                                            <div class="rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2.5">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Admin</p>
                                                <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $adminNote }}</p>
                                            </div>
                                        @endif
                                        @if ($courierNote)
                                            <div class="rounded-lg border border-[#E7DFCF] bg-white px-3 py-2.5">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Courier</p>
                                                <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $courierNote }}</p>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                        @if ($segment === 'dispatched')
                            @php($events = $trackingByOrder[$order->id]['events'] ?? [])
                            @php($courierStatus = $trackingByOrder[$order->id]['status'] ?? null)
                            <tr wire:key="order-tracking-{{ $order->id }}">
                                <td class="px-4 pt-2 pb-6" colspan="{{ $readOnly ? 2 : 8 }}">
                                    <div
                                        x-data="{ open: true }"
                                        class="ml-8 mb-3 overflow-hidden rounded-lg border border-[#EFE7D6] bg-white"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between gap-3 bg-[#FAF6EF] px-4 py-3 text-left hover:bg-[#F5EFE3]"
                                            @click="open = ! open"
                                            :aria-expanded="open.toString()"
                                        >
                                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[#1E1E1E]">
                                                <span class="font-semibold uppercase tracking-wide">{{ $order->courier?->name ?? '—' }}</span>
                                                <span class="text-[#C9B99A]">|</span>
                                                <span class="break-all text-[#6B6459]">{{ $order->courier_tracker ?: '—' }}</span>
                                                <span class="text-[#C9B99A]">|</span>
                                                <span class="font-semibold uppercase tracking-wide">
                                                    <span wire:loading.delay wire:target="refreshCourierStatuses">Loading…</span>
                                                    <span wire:loading.remove wire:target="refreshCourierStatuses">
                                                        {{ $courierStatus ? strtoupper($courierStatus) : '—' }}
                                                    </span>
                                                </span>
                                            </span>
                                            <svg
                                                class="h-4 w-4 shrink-0 text-[#8C8474] transition-transform"
                                                :class="open ? 'rotate-180' : ''"
                                                width="16"
                                                height="16"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                xmlns="http://www.w3.org/2000/svg"
                                                aria-hidden="true"
                                            >
                                                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>
                                        <div class="border-t border-[#E7DFCF] bg-white px-4 py-3" x-show="open" x-cloak>
                                            @if (count($events) === 0)
                                                <p class="text-xs text-[#8C8474]">No tracking updates yet.</p>
                                            @else
                                                <ul class="m-0 list-none space-y-1.5 p-0">
                                                    @foreach ($events as $event)
                                                        <li class="flex gap-3 text-xs leading-snug text-[#1E1E1E]">
                                                            <span class="w-[5.5rem] shrink-0 tabular-nums text-[#5B8DEF]">{{ $event['at'] }}</span>
                                                            <span>{{ $event['message'] }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $readOnly ? 2 : 8 }}" class="px-4 py-8 text-center text-[#8C8474]">No orders found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="px-4 py-3 border-t border-[#E7DFCF]">{{ $orders->links() }}</div>
        @endif
    </div>

    @endif

    @teleport('body')
        <div wire:key="admin-orders-overlays">
            @unless ($readOnly)
            <div wire:key="admin-order-selection-bar"
                @class([
                    'admin-order-selection-bar fixed bottom-0 left-0 right-0 z-50',
                    'hidden' => $selectedCount === 0,
                ])
                role="status"
                aria-live="polite">
                <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-4 py-2 text-sm sm:px-6">
                    <button type="button" wire:click="clearSelection"
                        class="admin-order-selection-bar__clear text-xs transition">
                        Clear selection
                    </button>
                    <div class="flex items-center gap-4">
                        <p class="font-medium tabular-nums tracking-wide">
                            {{ number_format($selectedCount) }} selected:
                            &#2547; {{ number_format($selectedTotal, 0) }} Tk
                        </p>
                        @if ($segment === 'new')
                            <button type="button" wire:click="openSendTo"
                                class="rounded-full bg-[#C9A227] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#b8931f]">
                                Send to
                            </button>
                            <button type="button" wire:click="openDispatch"
                                class="rounded-full border border-white/40 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white hover:bg-white/20">
                                Dispatch
                            </button>
                        @endif
                        @if ($segment === 'dispatched')
                            <button type="button"
                                wire:click="markSelectedDelivered"
                                wire:confirm="Mark {{ $selectedCount }} selected order(s) as delivered?"
                                class="rounded-full bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                Delivered
                            </button>
                        @endif
                        <button type="button"
                            wire:click="listSelectedProducts"
                            wire:loading.attr="disabled"
                            wire:target="listSelectedProducts"
                            class="rounded-full border border-white/40 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white hover:bg-white/20 disabled:opacity-60">
                            List products
                        </button>
                        <button type="button"
                            wire:click="deleteSelected"
                            wire:confirm="Delete {{ $selectedCount }} selected order(s) and restore product stock?"
                            class="rounded-full border border-rose-300 bg-rose-600/90 px-4 py-1.5 text-xs font-semibold text-white hover:bg-rose-600">
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            <div>
                @if ($showSendToModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closeSendModals">
                        <div class="w-full max-w-md rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Send to courier</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        Send {{ number_format($selectedCount) }} selected order(s) to the courier API.
                                        Status stays unchanged until you mark them dispatched.
                                    </p>
                                </div>
                                <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            @if ($apiCouriers->isEmpty())
                                <p class="text-sm text-rose-600">No courier APIs are configured. Add credentials in <code class="text-xs">.env</code>.</p>
                            @else
                                <div>
                                    <label class="block text-sm font-medium mb-1">Courier</label>
                                    <select wire:model="sendToCourierSlug"
                                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                        @foreach ($apiCouriers as $apiCourier)
                                            <option value="{{ $apiCourier->slug }}">{{ $apiCourier->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('sendToCourierSlug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex flex-wrap gap-3 pt-1">
                                    <button type="button" wire:click="startBulkSend"
                                        class="rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                                        Start sending
                                    </button>
                                    <button type="button" wire:click="closeSendModals"
                                        class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                        Cancel
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showDispatchModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closeSendModals">
                        <div class="w-full max-w-md rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Dispatch orders</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        Mark {{ number_format($selectedCount) }} selected order(s) as dispatched and update courier book balance.
                                    </p>
                                </div>
                                <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            @if ($dispatchCouriers->isEmpty())
                                <p class="text-sm text-rose-600">No active couriers found. Add a courier first.</p>
                            @else
                                <div>
                                    <label class="block text-sm font-medium mb-1">Courier</label>
                                    <select wire:model="dispatchCourierId"
                                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                        @foreach ($dispatchCouriers as $courier)
                                            <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('dispatchCourierId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex flex-wrap gap-3 pt-1">
                                    <button type="button" wire:click="submitBulkDispatch"
                                        class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black">
                                        Mark dispatched
                                    </button>
                                    <button type="button" wire:click="closeSendModals"
                                        class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                        Cancel
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showPartialModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closePartialModal">
                        <div class="w-full max-w-lg rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4 max-h-[85vh] overflow-y-auto" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Partial return</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">Order #{{ $partialOrderNumber }}</p>
                                </div>
                                <button type="button" wire:click="closePartialModal" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            <div class="space-y-3">
                                @forelse ($partialItems as $item)
                                    <div wire:key="partial-item-{{ $item['id'] }}" class="flex items-center gap-3 rounded-lg border border-[#EFE7D6] px-3 py-2">
                                        @if ($item['image'])
                                            <img src="{{ $item['image'] }}" alt="" class="h-10 w-10 rounded object-cover">
                                        @else
                                            <div class="h-10 w-10 rounded bg-[#FAF6EF]"></div>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium">{{ $item['name'] }}</p>
                                            <p class="text-xs text-[#8C8474]">Ordered: {{ $item['quantity'] }}</p>
                                        </div>
                                        <div class="w-24">
                                            <label class="mb-0.5 block text-[10px] uppercase tracking-wide text-[#8C8474]">Returned</label>
                                            <input type="number" min="0" max="{{ $item['quantity'] }}"
                                                wire:model="partialReturns.{{ $item['id'] }}"
                                                class="w-full rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-sm">
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-[#8C8474]">No products on this order.</p>
                                @endforelse
                                @error('partialReturns') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                @error('partialReturns.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">Collected Tk</label>
                                <input type="number" min="0" step="1" wire:model="partialCollectedTk"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                @error('partialCollectedTk') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-[#8C8474]">
                                    All products returned → Cancelled. Some kept → Delivered. Both set has_return.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-3 pt-1">
                                <button type="button" wire:click="submitPartialReturn"
                                    class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black">
                                    Submit partial
                                </button>
                                <button type="button" wire:click="closePartialModal"
                                    class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showBulkSendProgress)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @if (! $bulkSending) wire:click.self="closeSendModals" @endif>
                        <div class="w-full max-w-lg rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4 max-h-[85vh] flex flex-col" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Sending to {{ strtoupper($sendToCourierSlug) }}</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        @if ($bulkSending)
                                            Sending selected orders to the courier API…
                                        @else
                                            Finished.
                                        @endif
                                    </p>
                                </div>
                                @unless ($bulkSending)
                                    <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                                @endunless
                            </div>

                            <ul class="space-y-2 overflow-y-auto flex-1 pr-1">
                                @foreach ($bulkSendRows as $row)
                                    <li wire:key="bulk-send-{{ $row['order_id'] }}"
                                        class="rounded-lg border border-[#EFE7D6] px-3 py-2 text-sm">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="font-medium">#{{ $row['order_number'] }} · {{ $row['customer'] }}</p>
                                                @if ($row['message'])
                                                    <p class="text-xs mt-0.5 {{ $row['status'] === 'failed' ? 'text-rose-600' : 'text-[#6B6459]' }}">
                                                        {{ $row['message'] }}
                                                    </p>
                                                @endif
                                            </div>
                                            <span @class([
                                                'shrink-0 text-xs font-semibold uppercase tracking-wide',
                                                'text-[#8C8474]' => $row['status'] === 'pending',
                                                'text-amber-700' => $row['status'] === 'sending',
                                                'text-emerald-700' => $row['status'] === 'success',
                                                'text-rose-600' => $row['status'] === 'failed',
                                            ])>
                                                @switch($row['status'])
                                                    @case('pending') Waiting @break
                                                    @case('sending') Sending @break
                                                    @case('success') Success @break
                                                    @case('failed') Failed @break
                                                    @default {{ $row['status'] }}
                                                @endswitch
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            @unless ($bulkSending)
                                <button type="button" wire:click="closeSendModals"
                                    class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black self-start">
                                    Done
                                </button>
                            @endunless
                        </div>
                    </div>
                @endif
            </div>
            @endunless
        </div>
    @endteleport
</div>
