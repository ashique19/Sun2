@props(['order'])

@if ($order->is_replacement && $order->relationLoaded('exchangeOf') && $order->exchangeOf)
    <a href="{{ route('admin.orders.show', $order->exchangeOf) }}" wire:navigate
        class="text-[11px] font-medium text-sky-700 hover:underline">
        Exchange of #{{ $order->exchangeOf->order_number }}
    </a>
@endif
@if ($order->relationLoaded('replacements') && $order->replacements->isNotEmpty())
    <span class="text-[11px] text-[#6B6459]">
        Replaced by
        @foreach ($order->replacements as $replacement)
            <a href="{{ route('admin.orders.show', $replacement) }}" wire:navigate
                class="font-medium text-sky-700 hover:underline">#{{ $replacement->order_number }}</a>@if (! $loop->last), @endif
        @endforeach
    </span>
@endif
