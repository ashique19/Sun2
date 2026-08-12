@php
    $hasData = ((float) ($report['grand_total'] ?? 0)) > 0;
    $series = collect($report['categories'] ?? [])
        ->map(fn (array $row) => [
            'label' => $row['name'],
            'color' => $row['color'],
            'values' => $row['values'],
        ])
        ->all();
@endphp

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Revenue by category</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Revenue by category</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Delivered line totals by product category, stacked by order created month for the selected year.
            </p>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-[#6B6459]">Year</label>
            <select wire:model.live="year"
                class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @foreach ($years as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-admin.analytics-subnav active="category" />

    @if (! $hasData)
        <div class="rounded-xl border border-dashed border-[#E0D6C2] bg-white px-6 py-16 text-center">
            <p class="text-sm font-medium text-[#6B6459]">No delivered category revenue for {{ $year }} yet.</p>
            <p class="mt-1 text-sm text-[#8C8474]">Deliver orders with products in categories to populate this chart.</p>
        </div>
    @else
        <div class="mb-6 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $year }} stacked by month</h2>
                    <p class="mt-1 text-xs text-[#8C8474]">Each bar is a month; segments are categories.</p>
                </div>
                <p class="text-sm font-semibold tabular-nums text-[#1E1E1E]">
                    Year total: &#2547; {{ number_format((float) $report['grand_total'], 0) }}
                </p>
            </div>
            <x-admin.stacked-bar-chart
                :labels="$report['months']"
                :series="$series"
                :height="240"
            />
        </div>

        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
            <div class="border-b border-[#EFE7D6] px-4 py-3 sm:px-5">
                <h2 class="text-sm font-semibold text-[#1E1E1E]">Category × month</h2>
                <p class="mt-1 text-xs text-[#8C8474]">Uncategorized covers lines without a product category.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-xs sm:text-sm">
                    <thead class="bg-[#FAF6EF] text-left text-[#8C8474]">
                        <tr class="border-b border-[#EFE7D6]">
                            <th class="sticky left-0 z-10 bg-[#FAF6EF] px-4 py-2.5 font-medium">Category</th>
                            @foreach ($report['months'] as $label)
                                <th class="px-2 py-2.5 text-right font-medium">{{ $label }}</th>
                            @endforeach
                            <th class="px-4 py-2.5 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F0EBE0]">
                        @foreach ($report['categories'] as $row)
                            <tr wire:key="cat-rev-{{ $year }}-{{ $row['id'] ?? 'none' }}">
                                <td class="sticky left-0 z-10 bg-white px-4 py-2 font-medium text-[#1E1E1E]">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $row['color'] }}"></span>
                                        {{ $row['name'] }}
                                    </span>
                                </td>
                                @foreach ($row['values'] as $cell)
                                    <td class="px-2 py-2 text-right tabular-nums {{ $cell > 0 ? 'text-[#1E1E1E]' : 'text-[#C4BBA8]' }}">
                                        {{ $cell > 0 ? '৳ '.number_format($cell, 0) : '—' }}
                                    </td>
                                @endforeach
                                <td class="px-4 py-2 text-right font-semibold tabular-nums text-[#1E1E1E]">
                                    &#2547; {{ number_format((float) $row['total'], 0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-[#EFE7D6] bg-[#FAF6EF] text-sm font-semibold text-[#1E1E1E]">
                        <tr>
                            <td class="sticky left-0 z-10 bg-[#FAF6EF] px-4 py-2.5">Month total</td>
                            @foreach ($report['month_totals'] as $total)
                                <td class="px-2 py-2.5 text-right tabular-nums">
                                    &#2547; {{ number_format((float) $total, 0) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                &#2547; {{ number_format((float) $report['grand_total'], 0) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
</div>
