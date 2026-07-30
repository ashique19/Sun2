@props([
    'labels' => [],
    'series' => [],
    'height' => 220,
    'format' => 'number', // number | money
])

@php
    /** @var list<string> $labels */
    /** @var list<array{label: string, color: string, values: list<float|int>}> $series */
    $labels = array_values($labels);
    $series = array_values($series);
    $groupCount = count($labels);
    $seriesCount = count($series);

    $max = 0.0;
    foreach ($series as $serie) {
        foreach ($serie['values'] ?? [] as $value) {
            $max = max($max, (float) $value);
        }
    }
    $max = $max > 0 ? $max : 1.0;

    $padLeft = 36;
    $padRight = 12;
    $padTop = 16;
    $padBottom = 36;
    $plotWidth = 640;
    $plotHeight = max(120, (int) $height);
    $width = $padLeft + $plotWidth + $padRight;
    $heightTotal = $padTop + $plotHeight + $padBottom;

    $groupGap = $groupCount > 0 ? $plotWidth / $groupCount : $plotWidth;
    $innerGap = 4;
    $barWidth = $seriesCount > 0
        ? max(4, min(18, ($groupGap - 12 - ($seriesCount - 1) * $innerGap) / $seriesCount))
        : 8;

    $formatValue = function (float $value) use ($format): string {
        if ($format === 'money') {
            if ($value >= 100000) {
                return '৳'.number_format($value / 1000, 0).'k';
            }

            return '৳'.number_format($value, 0);
        }

        return number_format($value, 0);
    };
@endphp

<div {{ $attributes->class('w-full') }}>
    <div class="mb-3 flex flex-wrap items-center gap-4 text-xs text-[#6B6459]">
        @foreach ($series as $serie)
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $serie['color'] }}"></span>
                {{ $serie['label'] }}
            </span>
        @endforeach
    </div>

    <div class="w-full overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $heightTotal }}" class="min-w-[36rem] w-full" role="img">
            {{-- grid --}}
            @foreach ([0.25, 0.5, 0.75, 1] as $tick)
                @php $y = $padTop + $plotHeight * (1 - $tick); @endphp
                <line x1="{{ $padLeft }}" y1="{{ $y }}" x2="{{ $padLeft + $plotWidth }}" y2="{{ $y }}"
                    stroke="#EFE7D6" stroke-width="1" />
                <text x="{{ $padLeft - 6 }}" y="{{ $y + 3 }}" text-anchor="end" fill="#8C8474" font-size="9">
                    {{ $formatValue($max * $tick) }}
                </text>
            @endforeach

            <line x1="{{ $padLeft }}" y1="{{ $padTop + $plotHeight }}"
                x2="{{ $padLeft + $plotWidth }}" y2="{{ $padTop + $plotHeight }}"
                stroke="#E0D6C2" stroke-width="1" />

            @foreach ($labels as $index => $label)
                @php
                    $groupCenter = $padLeft + ($index + 0.5) * $groupGap;
                    $clusterWidth = $seriesCount * $barWidth + max(0, $seriesCount - 1) * $innerGap;
                    $clusterStart = $groupCenter - $clusterWidth / 2;
                @endphp

                <text x="{{ $groupCenter }}" y="{{ $padTop + $plotHeight + 18 }}"
                    text-anchor="middle" fill="#6B6459" font-size="10">{{ $label }}</text>

                @foreach ($series as $sIndex => $serie)
                    @php
                        $value = (float) ($serie['values'][$index] ?? 0);
                        $barH = $max > 0 ? ($value / $max) * $plotHeight : 0;
                        $x = $clusterStart + $sIndex * ($barWidth + $innerGap);
                        $y = $padTop + $plotHeight - $barH;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ max(0, $barH) }}"
                        rx="2" fill="{{ $serie['color'] }}">
                        <title>{{ $label }} · {{ $serie['label'] }}: {{ $format === 'money' ? '৳'.number_format($value, 0) : number_format($value, 0) }}</title>
                    </rect>
                @endforeach
            @endforeach
        </svg>
    </div>
</div>
