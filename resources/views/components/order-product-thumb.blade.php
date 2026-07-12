@props([
    'item',
    'size' => 'sm',
    'showQuantity' => false,
    'showReturn' => false,
])

@php
    $imageUrl = $item->imageUrl();
    $productName = $item->displayName();
    $isLarge = in_array($size, ['md', 'lg'], true);
    $thumbSize = $isLarge ? 'aspect-square w-full' : 'h-12 w-12';
    $quantityClass = $isLarge ? 'text-2xl font-bold' : 'text-xs font-semibold';
    $returnedQty = (int) ($item->returned_quantity ?? 0);
    $returnReceived = (bool) ($item->return_received ?? false);
@endphp

<div {{ $attributes->merge(['class' => $isLarge ? 'flex w-full flex-col items-center gap-1.5' : 'inline-flex flex-col items-center gap-1.5 shrink-0']) }}>
    @if ($imageUrl)
        <button type="button"
            title="{{ $productName }} — click to enlarge"
            wire:click.stop="openProductImage({{ $item->id }})"
            @class([
                $thumbSize,
                'relative shrink-0 overflow-hidden rounded-md border bg-[#FAF6EF] p-0 cursor-zoom-in transition hover:ring-2 hover:ring-[#C9A227]/60 focus:outline-none focus:ring-2 focus:ring-[#C9A227]',
                'border-amber-400 ring-1 ring-amber-300' => $showReturn && $returnedQty > 0 && ! $returnReceived,
                'border-emerald-400 ring-1 ring-emerald-300' => $showReturn && $returnedQty > 0 && $returnReceived,
                'border-[#E7DFCF]' => ! ($showReturn && $returnedQty > 0),
            ])>
            <img src="{{ $imageUrl }}" alt="{{ $productName }}" draggable="false"
                class="absolute inset-0 h-full w-full object-cover pointer-events-none select-none">
        </button>
    @else
        <div @class([
            $thumbSize,
            'shrink-0 flex items-center justify-center rounded-md border bg-[#FAF6EF] text-xs text-[#8C8474]',
            'border-amber-400' => $showReturn && $returnedQty > 0 && ! $returnReceived,
            'border-emerald-400' => $showReturn && $returnedQty > 0 && $returnReceived,
            'border-[#E7DFCF]' => ! ($showReturn && $returnedQty > 0),
        ])>
            ?
        </div>
    @endif

    @if ($showReturn && $returnedQty > 0)
        <span @class([
            'text-[10px] font-semibold leading-none tabular-nums',
            'text-amber-700' => ! $returnReceived,
            'text-emerald-700' => $returnReceived,
        ])>
            R&times;{{ $returnedQty }}{{ $returnReceived ? ' ✓' : '' }}
        </span>
    @endif

    @if ($showQuantity)
        <span @class([
            $quantityClass,
            'leading-none tabular-nums',
            'text-rose-600' => $item->quantity > 1,
            'text-[#1E1E1E]' => $item->quantity <= 1 && $isLarge,
            'text-[#6B6459]' => $item->quantity <= 1 && ! $isLarge,
        ])>
            &times;{{ $item->quantity }}
        </span>
    @endif
</div>
