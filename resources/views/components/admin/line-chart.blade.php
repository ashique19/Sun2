@props([
    'labels' => [],
    'series' => [],
    'height' => 280,
    'format' => 'number', // number | money
])

@php
    /** @var list<string> $labels */
    /** @var list<array{label: string, color: string, values: list<float|int>}> $series */
    $labels = array_values($labels);
    $series = array_values($series);
    $pointCount = count($labels);

    $max = 0.0;
    $min = 0.0;
    foreach ($series as $serie) {
        foreach ($serie['values'] ?? [] as $value) {
            $max = max($max, (float) $value);
            $min = min($min, (float) $value);
        }
    }
    if ($max <= 0.0 && $min >= 0.0) {
        $max = 1.0;
    }
    $span = max(1.0, $max - $min);

    $padLeft = 44;
    $padRight = 12;
    $padTop = 16;
    $padBottom = 36;
    $plotWidth = 720;
    $plotHeight = max(140, (int) $height);
    $width = $padLeft + $plotWidth + $padRight;
    $heightTotal = $padTop + $plotHeight + $padBottom;

    $xAt = function (int $index) use ($pointCount, $padLeft, $plotWidth): float {
        if ($pointCount <= 1) {
            return $padLeft + $plotWidth / 2;
        }

        return $padLeft + ($index / ($pointCount - 1)) * $plotWidth;
    };

    $yAt = function (float $value) use ($min, $span, $padTop, $plotHeight): float {
        return $padTop + $plotHeight * (1 - (($value - $min) / $span));
    };

    $formatValue = function (float $value) use ($format): string {
        $prefix = $value < 0 ? '-' : '';
        $abs = abs($value);
        if ($format === 'money') {
            if ($abs >= 100000) {
                return $prefix.'৳'.number_format($abs / 1000, 0).'k';
            }

            return $prefix.'৳'.number_format($abs, 0);
        }

        return $prefix.number_format($abs, 0);
    };

    $zeroY = $min < 0 && $max > 0 ? $yAt(0.0) : null;
@endphp

<div {{ $attributes->class('w-full') }}>
    <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-[#6B6459]">
        @foreach ($series as $serie)
            <span class="inline-flex items-center gap-1.5">
                <span class="h-0.5 w-3 rounded-full" style="background: {{ $serie['color'] }}"></span>
                {{ $serie['label'] }}
            </span>
        @endforeach
    </div>

    <div class="w-full overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $heightTotal }}" class="min-w-[40rem] w-full" role="img" aria-label="Year compare line chart">
            @foreach ([0, 0.25, 0.5, 0.75, 1] as $tick)
                @php
                    $value = $min + $span * $tick;
                    $y = $yAt($value);
                @endphp
                <line x1="{{ $padLeft }}" y1="{{ $y }}" x2="{{ $padLeft + $plotWidth }}" y2="{{ $y }}"
                    stroke="#EFE7D6" stroke-width="1" />
                <text x="{{ $padLeft - 6 }}" y="{{ $y + 3 }}" text-anchor="end" fill="#8C8474" font-size="9">
                    {{ $formatValue($value) }}
                </text>
            @endforeach

            @if ($zeroY !== null)
                <line x1="{{ $padLeft }}" y1="{{ $zeroY }}" x2="{{ $padLeft + $plotWidth }}" y2="{{ $zeroY }}"
                    stroke="#C4BBA8" stroke-width="1" stroke-dasharray="4 3" />
            @endif

            <line x1="{{ $padLeft }}" y1="{{ $padTop + $plotHeight }}"
                x2="{{ $padLeft + $plotWidth }}" y2="{{ $padTop + $plotHeight }}"
                stroke="#E0D6C2" stroke-width="1" />

            @foreach ($labels as $index => $label)
                <text x="{{ $xAt($index) }}" y="{{ $padTop + $plotHeight + 18 }}"
                    text-anchor="middle" fill="#8C8474" font-size="10">{{ $label }}</text>
            @endforeach

            @foreach ($series as $serie)
                @php
                    $points = [];
                    foreach (array_values($serie['values'] ?? []) as $index => $value) {
                        if ($index >= $pointCount) {
                            break;
                        }
                        $points[] = $xAt($index).','.$yAt((float) $value);
                    }
                    $polyline = implode(' ', $points);
                @endphp
                @if ($polyline !== '')
                    <polyline fill="none" stroke="{{ $serie['color'] }}" stroke-width="2"
                        stroke-linejoin="round" stroke-linecap="round" points="{{ $polyline }}" />
                    @foreach (array_values($serie['values'] ?? []) as $index => $value)
                        @if ($index < $pointCount)
                            <circle cx="{{ $xAt($index) }}" cy="{{ $yAt((float) $value) }}" r="2.5"
                                fill="{{ $serie['color'] }}">
                                <title>{{ $serie['label'] }} {{ $labels[$index] ?? '' }}: {{ $formatValue((float) $value) }}</title>
                            </circle>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </svg>
    </div>
</div>
