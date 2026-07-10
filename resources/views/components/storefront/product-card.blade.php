@props(['product'])

@php
    $image = \App\Support\StorefrontAssets::url($product->primaryImagePath());
@endphp

<a href="{{ route('product.show', $product) }}" wire:navigate
   class="group block rounded-xl bg-white border border-[#EFE7D6] overflow-hidden hover:shadow-md transition">
    @if ($image)
        <img src="{{ $image }}" alt="{{ $product->name }}"
            class="aspect-square w-full object-cover bg-[#F1EADB] group-hover:scale-[1.02] transition-transform duration-300">
    @else
        <div class="aspect-square bg-[#F1EADB] flex items-center justify-center text-4xl text-[#C9A227]">
            &#9670;
        </div>
    @endif
    <div class="p-4">
        @if ($product->category)
            <p class="text-[10px] uppercase tracking-wider text-[#C9A227] mb-1">{{ $product->category->name }}</p>
        @endif
        <h3 class="font-medium text-sm leading-snug line-clamp-2 group-hover:text-[#C9A227] transition">{{ $product->name }}</h3>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="font-semibold text-[#1E1E1E]">&#2547; {{ number_format($product->price, 0) }}</span>
            @if ($product->compare_at_price && $product->compare_at_price > $product->price)
                <span class="text-xs text-[#8C8474] line-through">&#2547; {{ number_format($product->compare_at_price, 0) }}</span>
            @endif
        </div>
        @unless ($product->isInStock())
            <p class="mt-1 text-xs text-rose-600">Out of stock</p>
        @endunless
    </div>
</a>
