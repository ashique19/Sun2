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
        'lg' => 'text-lg text-[#5C564C] line-through',
        'sm' => 'text-xs text-[#5C564C] line-through',
        default => 'text-xs text-[#5C564C] line-through',
    };

    $priceClass = match ($size) {
        'lg' => 'text-2xl font-semibold text-[#1E1E1E]',
        'sm' => 'text-sm text-[#5C564C]',
        default => 'font-semibold text-[#1E1E1E]',
    };

    $unitClass = match ($size) {
        'lg' => 'font-normal text-[#6B6459]',
        'sm' => 'font-normal text-[#5C564C]',
        default => 'font-normal text-[#5C564C]',
    };
@endphp

<div {{ $attributes }}>
    @if ($hasCompare)
        <div class="{{ $compareClass }}">&#2547; {{ \App\Support\Bangla::money((float) $product->compare_at_price) }}</div>
    @endif
    <p class="{{ $priceClass }}">
        <span>&#2547; {{ \App\Support\Bangla::money((float) $product->price) }}</span><span class="{{ $unitClass }}">/{{ $unit }}</span>
    </p>
</div>
