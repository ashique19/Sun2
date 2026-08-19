@props(['order'])

@php
    $classes = match ($order->status) {
        'new', 'confirmed' => 'bg-amber-50 text-amber-800',
        'dispatched' => 'bg-sky-50 text-sky-800',
        'delivered' => 'bg-emerald-50 text-emerald-800',
        'returned', 'cancelled' => 'bg-rose-50 text-rose-800',
        default => 'bg-[#FAF6EF] text-[#6B6459]',
    };
@endphp

<span {{ $attributes->class(["inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $order->customerStatusLabel() }}
</span>
