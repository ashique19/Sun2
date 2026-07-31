@php
    $monthColors = [
        1 => '#8B5E3C', 2 => '#A67C52', 3 => '#C9A227', 4 => '#B8956A',
        5 => '#6B8F71', 6 => '#4F7A5A', 7 => '#3D6B8E', 8 => '#2F6F4E',
        9 => '#C45C26', 10 => '#A04820', 11 => '#7A4E3A', 12 => '#5C4033',
    ];

    $months = $yearOverview['months'];
    $activeMonth = $monthBreakdown;

    $yearSegments = collect($months)
        ->filter(fn (array $row) => $row['revenue'] > 0)
        ->map(fn (array $row) => [
            'key' => 'month-'.$row['month'],
            'label' => $row['label'],
            'value' => $row['revenue'],
            'color' => $monthColors[$row['month']] ?? '#8C8474',
            'action' => 'selectMonth('.$row['month'].')',
        ])
        ->values()
        ->all();

    $monthSegments = $activeMonth
        ? collect($activeMonth['segments'])
            ->map(fn (array $segment) => [
                ...$segment,
                'action' => "openMetric('{$segment['key']}')",
            ])
            ->all()
        : [];

    $metricCards = $activeMonth ? [
        [
            'key' => 'revenue',
            'label' => 'Revenue',
            'hint' => 'Collected on delivered orders',
            'value' => $activeMonth['revenue'],
            'tone' => 'text-[#1F4E79]',
        ],
        [
            'key' => 'direct',
            'label' => 'Direct cost',
            'hint' => 'COGS + pack + courier + COD',
            'value' => $activeMonth['direct'],
            'tone' => 'text-[#C45C26]',
        ],
        [
            'key' => 'indirect',
            'label' => 'Indirect cost',
            'hint' => 'Expenses this month',
            'value' => $activeMonth['indirect'],
            'tone' => 'text-[#6B6459]',
        ],
        [
            'key' => 'profit',
            'label' => $activeMonth['profit'] >= 0 ? 'Profit' : 'Loss',
            'hint' => 'Revenue − direct − indirect',
            'value' => $activeMonth['profit'],
            'tone' => $activeMonth['profit'] < 0 ? 'text-rose-700' : 'text-[#2F6F4E]',
        ],
    ] : [];
@endphp

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Profit &amp; loss</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Profit &amp; loss</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Pick a month below, then open a P&amp;L card for the breakdown.
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-[#6B6459]">Year</label>
                <select wire:model.live="year"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    @foreach ($years as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <a href="{{ route('admin.expenses', ['year' => $year, 'month' => $month ?? now('Asia/Dhaka')->month]) }}"
                wire:navigate
                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                Expenses
            </a>
        </div>
    </div>

    <x-admin.analytics-subnav active="pnl" />

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <button type="button" wire:click="clearMonth"
            @class([
                'rounded-full px-3 py-1 transition',
                'bg-[#1E1E1E] text-white' => ! $activeMonth,
                'bg-white text-[#6B6459] border border-[#E0D6C2] hover:border-[#C9A227]' => $activeMonth,
            ])>
            {{ $year }} overview
        </button>
        <span class="text-[#8C8474]">→</span>
        @if ($activeMonth)
            <span class="rounded-full bg-[#C9A227] px-3 py-1 font-medium text-white">
                {{ $activeMonth['label'] }}
            </span>
            <span class="text-[#8C8474]">→</span>
            <span class="text-xs text-[#8C8474]">Click a card for details</span>
        @else
            <span class="text-[#8C8474]">Select a month</span>
        @endif
    </div>

    <div class="mb-6 rounded-xl border border-[#EFE7D6] bg-white p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-[#1E1E1E]">Months in {{ $year }}</h2>
            <p class="text-xs text-[#8C8474]">
                Year collected:
                <span class="font-medium tabular-nums text-[#1E1E1E]">&#2547; {{ number_format($yearOverview['revenue'], 0) }}</span>
                · {{ number_format($yearOverview['order_count']) }} delivered
            </p>
        </div>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
            @foreach ($months as $row)
                @php
                    $isSelected = (int) $month === (int) $row['month'];
                    $hasData = $row['revenue'] > 0 || $row['order_count'] > 0;
                @endphp
                <button type="button"
                    wire:click="selectMonth({{ $row['month'] }})"
                    wire:key="month-chip-{{ $row['month'] }}"
                    @class([
                        'rounded-xl border px-3 py-2.5 text-left transition',
                        'border-[#C9A227] bg-[#FAF6EF] ring-1 ring-[#C9A227]/40' => $isSelected,
                        'border-[#EFE7D6] bg-white hover:border-[#C9A227]/70 hover:bg-[#FAF6EF]/60' => ! $isSelected && $hasData,
                        'border-[#F0EBE0] bg-[#FBF9F4] text-[#8C8474]' => ! $isSelected && ! $hasData,
                    ])>
                    <div class="flex items-center justify-between gap-1">
                        <span @class(['text-sm font-semibold', 'text-[#1E1E1E]' => $isSelected || $hasData])>
                            {{ $row['label'] }}
                        </span>
                        @if ($isSelected)
                            <span class="text-[10px] font-medium text-[#C9A227]">Selected</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xs tabular-nums {{ $hasData ? 'text-[#6B6459]' : 'text-[#B0A898]' }}">
                        &#2547; {{ number_format($row['revenue'], 0) }}
                    </div>
                    <div class="mt-0.5 text-[10px] {{ $hasData ? 'text-[#8C8474]' : 'text-[#C4BBA8]' }}">
                        {{ $row['order_count'] }} order{{ $row['order_count'] === 1 ? '' : 's' }}
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    @if (! $activeMonth)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-6">
            <div class="mb-4">
                <h2 class="text-sm font-semibold text-[#1E1E1E]">Revenue mix</h2>
                <p class="mt-1 text-xs text-[#8C8474]">
                    Optional view — same months as above. Prefer the month grid if the chart feels fiddly.
                </p>
            </div>
            @if ($yearSegments === [])
                <p class="rounded-lg border border-dashed border-[#E0D6C2] px-4 py-10 text-center text-sm text-[#8C8474]">
                    No delivered orders with collections in {{ $year }} yet.
                </p>
            @else
                <x-admin.donut-chart
                    :segments="$yearSegments"
                    :center-label="(string) $year"
                    :center-value="'৳'.number_format($yearOverview['revenue'], 0)"
                    :center-sub="number_format($yearOverview['order_count']).' delivered'"
                    :size="280"
                />
            @endif
        </div>
    @else
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-6">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $activeMonth['label'] }} · P&amp;L</h2>
                    <p class="mt-1 text-xs text-[#8C8474]">
                        {{ number_format($activeMonth['order_count']) }} delivered orders · tap any card for the full list
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="previousMonth"
                        class="rounded-full border border-[#E0D6C2] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        ← Prev
                    </button>
                    <button type="button" wire:click="nextMonth"
                        class="rounded-full border border-[#E0D6C2] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Next →
                    </button>
                    <button type="button" wire:click="clearMonth"
                        class="rounded-full border border-[#E0D6C2] px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Year overview
                    </button>
                </div>
            </div>

            <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($metricCards as $card)
                    <button type="button"
                        wire:click="openMetric('{{ $card['key'] }}')"
                        wire:key="metric-card-{{ $card['key'] }}"
                        class="rounded-xl border border-[#EFE7D6] bg-[#FAF6EF] px-4 py-3.5 text-left transition hover:border-[#C9A227] hover:bg-white">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">{{ $card['label'] }}</p>
                            <span class="text-[10px] font-medium text-[#C9A227]">Open →</span>
                        </div>
                        <p @class(['mt-1 text-xl font-semibold tabular-nums', $card['tone']])>
                            &#2547; {{ number_format($card['value'], 0) }}
                        </p>
                        <p class="mt-1 text-[11px] text-[#8C8474]">{{ $card['hint'] }}</p>
                    </button>
                @endforeach
            </div>

            @php($money = $activeMonth['money'])
            <div class="mb-6 grid gap-3 lg:grid-cols-3">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 text-sm space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Bill to customer</p>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Product price</span><span class="tabular-nums">&#2547; {{ number_format($money['product_price'], 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">+ Customer delivery</span><span class="tabular-nums">&#2547; {{ number_format($money['customer_delivery'], 0) }}</span></div>
                    @if ($money['other_charges'] > 0)
                        <div class="flex justify-between gap-3"><span class="text-[#6B6459]">+ Other charges</span><span class="tabular-nums">&#2547; {{ number_format($money['other_charges'], 0) }}</span></div>
                    @endif
                    @if ($money['discounts'] > 0)
                        <div class="flex justify-between gap-3 text-emerald-700"><span>− Discounts / coupons</span><span class="tabular-nums">&#2547; {{ number_format($money['discounts'], 0) }}</span></div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-[#F0EBE0] pt-2 font-semibold">
                        <span>Bill to customer</span>
                        <span class="tabular-nums">&#2547; {{ number_format($money['bill_to_customer'], 0) }}</span>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 text-sm space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Receivable from courier</p>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Collected / COD remittance</span><span class="tabular-nums">&#2547; {{ number_format($money['remittance_base'], 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">− Courier charge</span><span class="tabular-nums">&#2547; {{ number_format($money['courier_charge'], 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">− COD charge</span><span class="tabular-nums">&#2547; {{ number_format($money['cod_charge'], 2) }}</span></div>
                    <div class="flex justify-between gap-3 border-t border-[#F0EBE0] pt-2 font-semibold">
                        <span>Receivable from courier</span>
                        <span @class(['tabular-nums', 'text-rose-600' => $money['courier_receivable'] < 0])>&#2547; {{ number_format($money['courier_receivable'], 0) }}</span>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 text-sm space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Gross profit</p>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Receivable from courier</span><span class="tabular-nums">&#2547; {{ number_format($money['courier_receivable'], 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">− COGS</span><span class="tabular-nums">&#2547; {{ number_format($money['cogs'], 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">− Packaging</span><span class="tabular-nums">&#2547; {{ number_format($money['packaging'], 0) }}</span></div>
                    <div class="flex justify-between gap-3 border-t border-[#F0EBE0] pt-2 font-semibold">
                        <span>Gross profit</span>
                        <span @class(['tabular-nums', 'text-rose-600' => $money['gross_profit'] < 0])>&#2547; {{ number_format($money['gross_profit'], 0) }}</span>
                    </div>
                    @if ($money['indirect'] > 0)
                        <div class="flex justify-between gap-3 text-[#6B6459]"><span>− Indirect expenses</span><span class="tabular-nums">&#2547; {{ number_format($money['indirect'], 0) }}</span></div>
                        <div class="flex justify-between gap-3 border-t border-[#F0EBE0] pt-2 font-semibold">
                            <span>After indirect</span>
                            <span @class(['tabular-nums', 'text-rose-600' => $money['net_after_indirect'] < 0])>&#2547; {{ number_format($money['net_after_indirect'], 0) }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <details class="group rounded-xl border border-[#EFE7D6] bg-[#FBF9F4] open:bg-white">
                <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-[#6B6459] marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="inline-flex items-center gap-2">
                        <span class="text-[#C9A227] transition group-open:rotate-90">▸</span>
                        Show donut chart
                    </span>
                </summary>
                <div class="border-t border-[#EFE7D6] px-4 py-5">
                    <x-admin.donut-chart
                        :segments="$monthSegments"
                        center-label="Net"
                        :center-value="'৳'.number_format($activeMonth['profit'], 0)"
                        :center-sub="number_format($activeMonth['order_count']).' orders'"
                        :size="280"
                    />
                </div>
            </details>
        </div>
    @endif
</div>
