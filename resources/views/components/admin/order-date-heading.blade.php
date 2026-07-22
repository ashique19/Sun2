@props([
    'date' => null,
    'kind' => 'order', // order|dispatch
    'count' => null,
])

@php
    $tz = 'Asia/Dhaka';
    $carbon = $date instanceof \Carbon\CarbonInterface ? $date->timezone($tz) : null;
    $dateKey = $carbon?->toDateString() ?? '_none';
    $today = now($tz)->toDateString();
    $yesterday = now($tz)->subDay()->toDateString();

    if ($carbon === null) {
        $when = 'No date';
    } elseif ($dateKey === $today) {
        $when = 'Today · '.$carbon->format('d M Y');
    } elseif ($dateKey === $yesterday) {
        $when = 'Yesterday · '.$carbon->format('d M Y');
    } else {
        $when = $carbon->format('D, d M Y');
    }

    $prefix = $kind === 'dispatch' ? 'Dispatched' : 'Ordered';
    $countLabel = is_numeric($count)
        ? ((int) $count).' '.(((int) $count) === 1 ? 'order' : 'orders')
        : null;
@endphp

<div {{ $attributes->class('pt-2 first:pt-0') }} data-date-group="{{ $dateKey }}">
    <div class="flex items-center gap-3 px-0.5 pb-1.5 pt-1">
        <p class="shrink-0 text-xs font-semibold uppercase tracking-wide text-[#8C8474]">
            {{ $prefix }} · {{ $when }}@if ($countLabel) · {{ $countLabel }}@endif
        </p>
        <div class="h-px flex-1 bg-[#E7DFCF]"></div>
    </div>
</div>
