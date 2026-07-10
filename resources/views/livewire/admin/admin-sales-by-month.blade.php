<div>
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Sales by Month</h1>
            <p class="mt-1 text-sm text-[#8C8474]">Order sales vs delivered, by placed month.</p>
        </div>
        <div>
            <label class="block text-xs font-medium text-[#6B6459] mb-1">Year</label>
            <select wire:model.live="year"
                class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @foreach ($years as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Month</th>
                        <th class="px-4 py-3 font-medium text-right">Sales volume</th>
                        <th class="px-4 py-3 font-medium text-right">Sales value</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered volume</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered value</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-[#FAF6EF]/50">
                            <td class="px-4 py-3 font-medium whitespace-nowrap">{{ $row['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['sales_volume']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['sales_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['delivered_volume']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['delivered_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ $row['delivered_pct'] === null ? '—' : number_format($row['delivered_pct'], 1).'%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-[#FAF6EF] font-semibold">
                    <tr>
                        <td class="px-4 py-3">Year total</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals['sales_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($totals['sales_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals['delivered_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($totals['delivered_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ $totals['delivered_pct'] === null ? '—' : number_format($totals['delivered_pct'], 1).'%' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
