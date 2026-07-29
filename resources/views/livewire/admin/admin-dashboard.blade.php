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

    @php
        $primaryKeys = ['new', 'draft-ai', 'dispatched'];
        $primarySegments = collect($segments)->only($primaryKeys);
        $secondarySegments = collect($segments)->except($primaryKeys);
    @endphp

    <div x-data="{ moreOpen: false }" class="mb-8 space-y-3">
        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            @foreach ($primarySegments as $segmentKey => $segmentLabel)
                <a href="{{ route('admin.orders.'.$segmentKey) }}"
                    class="flex min-w-0 items-center justify-between gap-2 rounded-xl border border-[#EFE7D6] bg-white px-3 py-2.5 sm:px-4 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group"
                    title="{{ $segmentLabel }}">
                    <span class="truncate text-xs sm:text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</span>
                    <span class="shrink-0 text-lg sm:text-xl font-semibold tabular-nums text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</span>
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

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="px-6 py-5 border-b border-[#E7DFCF]">
            <h2 class="font-semibold text-lg">Last 30 Days</h2>
            <p class="text-sm text-[#8C8474] mt-1">Daily order and delivery quantity and value (by order placed date).</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Date</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Order Qty</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Order Value</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Delivery Qty</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Delivery Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($dailyTotals as $day)
                        <tr class="hover:bg-[#FAF6EF]/50">
                            <td class="px-4 py-3 whitespace-nowrap font-medium">{{ $day['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($day['order_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($day['order_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($day['delivery_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($day['delivery_value'], 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-[#8C8474]">No orders in the last 30 days.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($dailyTotals !== [])
                    <tfoot class="bg-[#FAF6EF] font-semibold border-t border-[#E7DFCF]">
                        <tr>
                            <td class="px-4 py-3">30-day total</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($periodTotals['order_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($periodTotals['order_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($periodTotals['delivery_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($periodTotals['delivery_value'], 0) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
