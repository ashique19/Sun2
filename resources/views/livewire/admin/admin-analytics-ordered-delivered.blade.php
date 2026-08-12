@php
    $ovdMonths = $orderedVsDelivered['months'];
    $ovdTotals = $orderedVsDelivered['totals'];
    $ovdLabels = array_column($ovdMonths, 'label');
    $hasOvdData = $ovdTotals['ordered_count'] > 0 || $ovdTotals['delivered_count'] > 0;
@endphp

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Ordered vs delivered</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Ordered vs delivered</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Same cohort: of orders created that month, how many were delivered and how much was collected.
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

    <x-admin.analytics-subnav active="ordered" />

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $year }} comparison</h2>
                <p class="mt-1 text-xs text-[#8C8474]">
                    Side-by-side volume and value for every month.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-right text-xs text-[#6B6459] sm:text-sm">
                <div>
                    <span class="text-[#8C8474]">Ordered</span>
                    <span class="ml-2 font-semibold tabular-nums text-[#1E1E1E]">
                        {{ number_format($ovdTotals['ordered_count']) }}
                        · &#2547; {{ number_format($ovdTotals['ordered_value'], 0) }}
                    </span>
                </div>
                <div>
                    <span class="text-[#8C8474]">Delivered</span>
                    <span class="ml-2 font-semibold tabular-nums text-[#1E1E1E]">
                        {{ number_format($ovdTotals['delivered_count']) }}
                        · &#2547; {{ number_format($ovdTotals['delivered_value'], 0) }}
                    </span>
                </div>
            </div>
        </div>

        @if (! $hasOvdData)
            <p class="rounded-lg border border-dashed border-[#E0D6C2] px-4 py-8 text-center text-sm text-[#8C8474]">
                No orders created in {{ $year }} yet.
            </p>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Order count</h3>
                    <x-admin.grouped-bar-chart
                        :labels="$ovdLabels"
                        :series="[
                            [
                                'label' => 'Ordered',
                                'color' => '#1F4E79',
                                'values' => array_column($ovdMonths, 'ordered_count'),
                            ],
                            [
                                'label' => 'Delivered (of that cohort)',
                                'color' => '#2F6F4E',
                                'values' => array_column($ovdMonths, 'delivered_count'),
                            ],
                        ]"
                        format="number"
                        :height="180"
                    />
                </div>
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Order value</h3>
                    <x-admin.grouped-bar-chart
                        :labels="$ovdLabels"
                        :series="[
                            [
                                'label' => 'Ordered (total)',
                                'color' => '#C9A227',
                                'values' => array_column($ovdMonths, 'ordered_value'),
                            ],
                            [
                                'label' => 'Collected (of that cohort)',
                                'color' => '#C45C26',
                                'values' => array_column($ovdMonths, 'delivered_value'),
                            ],
                        ]"
                        format="money"
                        :height="180"
                    />
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-xs sm:text-sm">
                    <thead class="text-left text-[#8C8474]">
                        <tr class="border-b border-[#EFE7D6]">
                            <th class="py-2 pr-3 font-medium">Month created</th>
                            <th class="py-2 pr-3 font-medium text-right">Ordered #</th>
                            <th class="py-2 pr-3 font-medium text-right">Ordered ৳</th>
                            <th class="py-2 pr-3 font-medium text-right">Delivered #</th>
                            <th class="py-2 font-medium text-right">Collected ৳</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F0EBE0]">
                        @foreach ($ovdMonths as $row)
                            <tr @class(['text-[#B0A898]' => $row['ordered_count'] === 0 && $row['delivered_count'] === 0])>
                                <td class="py-1.5 pr-3 font-medium text-[#1E1E1E]">{{ $row['label'] }}</td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">{{ number_format($row['ordered_count']) }}</td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">&#2547; {{ number_format($row['ordered_value'], 0) }}</td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">{{ number_format($row['delivered_count']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">&#2547; {{ number_format($row['delivered_value'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
