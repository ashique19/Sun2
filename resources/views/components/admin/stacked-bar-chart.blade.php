@props([
    'labels' => [],
    'series' => [],
    'height' => 240,
])

@php
    $labels = array_values($labels);
    $series = array_values($series);
    $groupCount = count($labels);

    $max = 0.0;
    for ($i = 0; $i < $groupCount; $i++) {
        $stack = 0.0;
        foreach ($series as $serie) {
            $stack += (float) ($serie['values'][$i] ?? 0);
        }
        $max = max($max, $stack);
    }
    $max = $max > 0 ? $max : 1.0;

    $padLeft = 40;
    $padRight = 12;
    $padTop = 16;
    $padBottom = 36;
    $plotWidth = 640;
    $plotHeight = max(140, (int) $height);
    $width = $padLeft + $plotWidth + $padRight;
    $heightTotal = $padTop + $plotHeight + $padBottom;
    $groupGap = $groupCount > 0 ? $plotWidth / $groupCount : $plotWidth;
    $barWidth = max(10, min(28, $groupGap * 0.55));
@endphp

<div {{ $attributes->class('w-full') }}>
    <div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-[#6B6459]">
        @foreach ($series as $serie)
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $serie['color'] }}"></span>
                {{ $serie['label'] }}
            </span>
        @endforeach
    </div>

    <div class="w-full overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $heightTotal }}" class="min-w-[36rem] w-full" role="img">
            @foreach ([0.25, 0.5, 0.75, 1] as $tick)
                @php $y = $padTop + $plotHeight * (1 - $tick); @endphp
                <line x1="{{ $padLeft }}" y1="{{ $y }}" x2="{{ $padLeft + $plotWidth }}" y2="{{ $y }}"
                    stroke="#EFE7D6" stroke-width="1" />
                <text x="{{ $padLeft - 6 }}" y="{{ $y + 3 }}" text-anchor="end" fill="#8C8474" font-size="9">
                    ৳{{ $max * $tick >= 100000 ? number_format(($max * $tick) / 1000, 0).'k' : number_format($max * $tick, 0) }}
                </text>
            @endforeach

            <line x1="{{ $padLeft }}" y1="{{ $padTop + $plotHeight }}"
                x2="{{ $padLeft + $plotWidth }}" y2="{{ $padTop + $plotHeight }}"
                stroke="#E0D6C2" stroke-width="1" />

            @foreach ($labels as $index => $label)
                @php
                    $groupCenter = $padLeft + ($index + 0.5) * $groupGap;
                    $x = $groupCenter - $barWidth / 2;
                    $yCursor = $padTop + $plotHeight;
                @endphp

                <text x="{{ $groupCenter }}" y="{{ $padTop + $plotHeight + 18 }}"
                    text-anchor="middle" fill="#6B6459" font-size="10">{{ $label }}</text>

                @foreach ($series as $serie)
                    @php
                        $value = (float) ($serie['values'][$index] ?? 0);
                        if ($value <= 0) {
                            continue;
                        }
                        $barH = ($value / $max) * $plotHeight;
                        $yCursor -= $barH;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $yCursor }}" width="{{ $barWidth }}" height="{{ $barH }}"
                        fill="{{ $serie['color'] }}">
                        <title>{{ $label }} · {{ $serie['label'] }}: ৳{{ number_format($value, 0) }}</title>
                    </rect>
                @endforeach
            @endforeach
        </svg>
    </div>
</div>
