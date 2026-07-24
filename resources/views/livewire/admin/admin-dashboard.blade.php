<div>
    <h1 class="font-serif text-3xl font-semibold mb-6">Dashboard</h1>

    @php
        $primaryKeys = ['new', 'draft-ai', 'dispatched'];
        $primarySegments = collect($segments)->only($primaryKeys);
        $secondarySegments = collect($segments)->except($primaryKeys);
    @endphp

    <div x-data="{ moreOpen: false }" class="mb-8 space-y-3">
        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            @foreach ($primarySegments as $segmentKey => $segmentLabel)
                <a href="{{ route('admin.orders.'.$segmentKey) }}"
                    class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group">
                    <p class="text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</p>
                    <p class="text-2xl sm:text-3xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</p>
                    <p class="text-xs text-[#C9A227] mt-2 font-medium">View orders &rarr;</p>
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
                class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($secondarySegments as $segmentKey => $segmentLabel)
                    <a href="{{ route('admin.orders.'.$segmentKey) }}"
                        class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group">
                        <p class="text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</p>
                        <p class="text-2xl sm:text-3xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</p>
                        <p class="text-xs text-[#C9A227] mt-2 font-medium">View orders &rarr;</p>
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
