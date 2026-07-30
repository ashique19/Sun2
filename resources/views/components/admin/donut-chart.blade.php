@props([
    'segments' => [],
    'centerLabel' => '',
    'centerValue' => '',
    'centerSub' => '',
    'size' => 280,
])

@php
    $allSegments = collect($segments)
        ->map(fn ($segment) => [
            'key' => (string) ($segment['key'] ?? ''),
            'label' => (string) ($segment['label'] ?? ''),
            'value' => max(0, (float) ($segment['value'] ?? 0)),
            'color' => (string) ($segment['color'] ?? '#8C8474'),
            'action' => $segment['action'] ?? null,
        ])
        ->values();

    $drawable = $allSegments->filter(fn (array $segment) => $segment['value'] > 0)->values();
    $total = (float) $drawable->sum('value');
    $radius = 90;
    $innerRadius = 54;
    $cx = 120;
    $cy = 120;
    $gap = $drawable->count() > 1 ? 1.2 : 0;

    $arcs = [];
    $angle = -90;

    if ($total <= 0) {
        $arcs[] = [
            'color' => '#E7DFCF',
            'fullRing' => true,
            'd' => null,
            'action' => null,
            'label' => 'No data',
            'value' => 0,
        ];
    } else {
        foreach ($drawable as $segment) {
            $sweep = ($segment['value'] / $total) * 360;
            $usable = max(0.01, $sweep - $gap);
            $start = $angle;
            $end = $angle + $usable;
            $angle += $sweep;

            $startRad = deg2rad($start);
            $endRad = deg2rad($end);
            $x1 = $cx + $radius * cos($startRad);
            $y1 = $cy + $radius * sin($startRad);
            $x2 = $cx + $radius * cos($endRad);
            $y2 = $cy + $radius * sin($endRad);
            $ix1 = $cx + $innerRadius * cos($endRad);
            $iy1 = $cy + $innerRadius * sin($endRad);
            $ix2 = $cx + $innerRadius * cos($startRad);
            $iy2 = $cy + $innerRadius * sin($startRad);
            $large = $usable > 180 ? 1 : 0;

            $arcs[] = [
                ...$segment,
                'fullRing' => false,
                'd' => sprintf(
                    'M %.3f %.3f A %d %d 0 %d 1 %.3f %.3f L %.3f %.3f A %d %d 0 %d 0 %.3f %.3f Z',
                    $x1,
                    $y1,
                    $radius,
                    $radius,
                    $large,
                    $x2,
                    $y2,
                    $ix1,
                    $iy1,
                    $innerRadius,
                    $innerRadius,
                    $large,
                    $ix2,
                    $iy2,
                ),
            ];
        }
    }
@endphp

<div {{ $attributes->class('flex flex-col items-center gap-5 lg:flex-row lg:items-start lg:gap-8') }}>
    <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
        <svg viewBox="0 0 240 240" class="h-full w-full" role="img" aria-label="{{ $centerLabel }} donut chart">
            @foreach ($arcs as $arc)
                @if ($arc['fullRing'] ?? false)
                    <circle cx="120" cy="120" r="{{ $radius }}" fill="none" stroke="{{ $arc['color'] }}" stroke-width="36" />
                @elseif (! empty($arc['action']))
                    <path d="{{ $arc['d'] }}"
                        fill="{{ $arc['color'] }}"
                        class="cursor-pointer transition opacity-90 hover:opacity-100"
                        wire:click="{{ $arc['action'] }}">
                        <title>{{ $arc['label'] }}: ৳{{ number_format($arc['value'], 0) }}</title>
                    </path>
                @else
                    <path d="{{ $arc['d'] }}" fill="{{ $arc['color'] }}">
                        <title>{{ $arc['label'] }}: ৳{{ number_format($arc['value'], 0) }}</title>
                    </path>
                @endif
            @endforeach
            <circle cx="120" cy="120" r="{{ $innerRadius - 2 }}" fill="#FAF6EF" />
            <text x="120" y="108" text-anchor="middle" fill="#8C8474" font-size="11">{{ $centerLabel }}</text>
            <text x="120" y="128" text-anchor="middle" fill="#1E1E1E" font-size="15" font-weight="600">{{ $centerValue }}</text>
            @if ($centerSub !== '')
                <text x="120" y="146" text-anchor="middle" fill="#6B6459" font-size="10">{{ $centerSub }}</text>
            @endif
        </svg>
    </div>

    <ul class="w-full max-w-sm space-y-2 text-sm">
        @forelse ($allSegments as $segment)
            <li>
                @if (! empty($segment['action']))
                    <button type="button"
                        wire:click="{{ $segment['action'] }}"
                        class="flex w-full items-center justify-between gap-3 rounded-lg border border-[#EFE7D6] bg-white px-3 py-2 text-left hover:border-[#C9A227] hover:bg-[#FAF6EF]">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $segment['color'] }}"></span>
                            <span class="truncate font-medium">{{ $segment['label'] }}</span>
                        </span>
                        <span class="tabular-nums text-[#6B6459]">&#2547; {{ number_format($segment['value'], 0) }}</span>
                    </button>
                @else
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-[#EFE7D6] bg-white px-3 py-2">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $segment['color'] }}"></span>
                            <span class="truncate font-medium">{{ $segment['label'] }}</span>
                        </span>
                        <span class="tabular-nums text-[#6B6459]">&#2547; {{ number_format($segment['value'], 0) }}</span>
                    </div>
                @endif
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-[#E0D6C2] px-3 py-6 text-center text-[#8C8474]">
                No delivered orders in this period.
            </li>
        @endforelse
    </ul>
</div>
