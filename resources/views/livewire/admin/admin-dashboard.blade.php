<div>
    <h1 class="font-serif text-3xl font-semibold mb-6">Dashboard</h1>

    <div class="grid sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5 gap-4 mb-8">
        @foreach ($segments as $segmentKey => $segmentLabel)
            <a href="{{ route('admin.orders.'.$segmentKey) }}"
                class="rounded-xl border border-[#EFE7D6] bg-white p-5 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group">
                <p class="text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</p>
                <p class="text-3xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</p>
                <p class="text-xs text-[#C9A227] mt-2 font-medium">View orders &rarr;</p>
            </a>
        @endforeach
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
