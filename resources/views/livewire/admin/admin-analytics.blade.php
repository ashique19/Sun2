@php
    $monthColors = [
        1 => '#8B5E3C', 2 => '#A67C52', 3 => '#C9A227', 4 => '#B8956A',
        5 => '#6B8F71', 6 => '#4F7A5A', 7 => '#3D6B8E', 8 => '#2F6F4E',
        9 => '#C45C26', 10 => '#A04820', 11 => '#7A4E3A', 12 => '#5C4033',
    ];

    $yearSegments = collect($yearOverview['months'])
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

    $monthSegments = $monthBreakdown
        ? collect($monthBreakdown['segments'])
            ->map(fn (array $segment) => [
                ...$segment,
                'action' => "openMetric('{$segment['key']}')",
            ])
            ->all()
        : [];
@endphp

<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Analytics</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Delivered-order economics — click a month, then a P&amp;L slice for details.
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

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-[#6B6459]">
        <button type="button" wire:click="clearMonth"
            class="{{ $month ? 'hover:text-[#C9A227]' : 'font-semibold text-[#1E1E1E]' }}">
            {{ $yearOverview['year'] }}
        </button>
        @if ($monthBreakdown)
            <span class="text-[#8C8474]">/</span>
            <span class="font-semibold text-[#1E1E1E]">{{ $monthBreakdown['label'] }}</span>
        @endif
    </div>

    @if (! $monthBreakdown)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-8">
            <div class="mb-4">
                <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $yearOverview['year'] }} · collected revenue by month</h2>
                <p class="mt-1 text-xs text-[#8C8474]">
                    Center is year total. Outer slices are months — click one to open cost &amp; profit.
                </p>
            </div>
            <x-admin.donut-chart
                :segments="$yearSegments"
                :center-label="(string) $yearOverview['year']"
                :center-value="'৳'.number_format($yearOverview['revenue'], 0)"
                :center-sub="number_format($yearOverview['order_count']).' delivered'"
                :size="300"
            />
        </div>
    @else
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-8">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $monthBreakdown['label'] }} · P&amp;L</h2>
                    <p class="mt-1 text-xs text-[#8C8474]">
                        Revenue = collected · Direct = COGS + pack + courier + COD · Indirect = expenses.
                        Click a head for details.
                    </p>
                </div>
                <button type="button" wire:click="clearMonth"
                    class="text-xs font-medium text-[#C9A227] hover:underline">
                    ← Back to {{ $yearOverview['year'] }}
                </button>
            </div>

            <x-admin.donut-chart
                :segments="$monthSegments"
                center-label="Net"
                :center-value="'৳'.number_format($monthBreakdown['profit'], 0)"
                :center-sub="number_format($monthBreakdown['order_count']).' orders'"
                :size="300"
            />

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">Revenue</p>
                    <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format($monthBreakdown['revenue'], 0) }}</p>
                </div>
                <div class="rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">Direct cost</p>
                    <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format($monthBreakdown['direct'], 0) }}</p>
                </div>
                <div class="rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">Indirect cost</p>
                    <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format($monthBreakdown['indirect'], 0) }}</p>
                </div>
                <div class="rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">{{ $monthBreakdown['profit'] >= 0 ? 'Profit' : 'Loss' }}</p>
                    <p @class(['mt-0.5 text-sm font-semibold tabular-nums', 'text-rose-700' => $monthBreakdown['profit'] < 0])>
                        &#2547; {{ number_format($monthBreakdown['profit'], 0) }}
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
