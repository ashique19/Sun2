@props([
    'product',
    'showCompare' => true,
    'size' => 'md',
])

@php
    $unit = $product->priceUnitLabel();
    $hasCompare = $showCompare
        && $product->compare_at_price !== null
        && (float) $product->compare_at_price > (float) $product->price;

    $compareClass = match ($size) {
        'lg' => 'text-lg text-[#8C8474] line-through',
        'sm' => 'text-xs text-[#8C8474] line-through',
        default => 'text-xs text-[#8C8474] line-through',
    };

    $priceClass = match ($size) {
        'lg' => 'text-2xl font-semibold text-[#1E1E1E]',
        'sm' => 'text-sm text-[#8C8474]',
        default => 'font-semibold text-[#1E1E1E]',
    };

    $unitClass = match ($size) {
        'lg' => 'font-normal text-[#6B6459]',
        'sm' => 'font-normal text-[#8C8474]',
        default => 'font-normal text-[#8C8474]',
    };
@endphp

<div {{ $attributes }}>
    @if ($hasCompare)
        <div class="{{ $compareClass }}">&#2547; {{ number_format((float) $product->compare_at_price, 0) }}</div>
    @endif
    <p class="{{ $priceClass }}">
        <span>&#2547; {{ number_format((float) $product->price, 0) }}</span><span class="{{ $unitClass }}">/{{ $unit }}</span>
    </p>
</div>
