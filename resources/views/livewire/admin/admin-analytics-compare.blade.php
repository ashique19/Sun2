@php
    $formatTotal = function (float $value) use ($chart): string {
        $prefix = $value < 0 ? '-' : '';
        $abs = abs($value);
        if ($chart['format'] === 'money') {
            return $prefix.'৳'.number_format($abs, 0);
        }

        return $prefix.number_format($abs, 0);
    };
@endphp

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Compare years</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Compare years</h1>
            <p class="mt-1 max-w-2xl text-sm text-[#8C8474]">
                Month-by-month lines for the last {{ \App\Services\Admin\AnalyticsYearCompareService::YEAR_COUNT }} years
                ({{ $chart['years'][0] }}–{{ $chart['years'][count($chart['years']) - 1] }}). Same cohorts as P&amp;L, ordered vs delivered, and category revenue.
            </p>
        </div>
    </div>

    <x-admin.analytics-subnav active="compare" />

    <div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Compare metric">
        @foreach ($metrics as $key => $meta)
            <button type="button"
                wire:click="$set('metric', '{{ $key }}')"
                @class([
                    'rounded-full border px-3 py-1.5 text-xs font-medium transition',
                    'border-[#1E1E1E] bg-[#1E1E1E] text-white' => $metric === $key,
                    'border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227]' => $metric !== $key,
                ])
                data-compare-metric="{{ $key }}">
                {{ $meta['label'] }}
            </button>
        @endforeach
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-6">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-[#1E1E1E]">{{ $chart['label'] }}</h2>
            <p class="mt-1 text-xs text-[#8C8474]">{{ $chart['hint'] }}</p>
        </div>

        <x-admin.line-chart
            :labels="$chart['labels']"
            :series="$chart['series']"
            :format="$chart['format']"
            :height="300"
        />
    </div>

    <div class="mt-6 rounded-xl border border-[#EFE7D6] bg-white p-5 sm:p-6">
        <h2 class="mb-3 text-sm font-semibold text-[#1E1E1E]">Year totals</h2>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($chart['year_totals'] as $row)
                <div class="rounded-lg border border-[#EFE7D6] px-3 py-2" data-year-total="{{ $row['year'] }}">
                    <div class="flex items-center gap-1.5 text-xs text-[#8C8474]">
                        <span class="h-2 w-2 rounded-full" style="background: {{ $row['color'] }}"></span>
                        {{ $row['year'] }}
                    </div>
                    <div @class([
                        'mt-1 text-sm font-semibold tabular-nums',
                        'text-rose-700' => $row['total'] < 0,
                        'text-[#1E1E1E]' => $row['total'] >= 0,
                    ])>
                        {{ $formatTotal($row['total']) }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
