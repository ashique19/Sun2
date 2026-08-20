@props(['product'])

@php
    $imagePath = $product->primaryImagePath();
@endphp

<a href="{{ route('product.show', $product) }}" wire:navigate
   class="group block rounded-xl bg-white border border-[#EFE7D6] overflow-hidden hover:shadow-md transition">
    @if ($imagePath)
        <x-storefront.listing-image
            :path="$imagePath"
            :alt="$product->name"
            class="aspect-square w-full object-cover bg-[#F1EADB] group-hover:scale-[1.02] transition-transform duration-300"
        />
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
        <div class="mt-2">
            <x-storefront.product-price :product="$product" />
        </div>
        @unless ($product->isInStock())
            <p class="mt-1 text-xs text-rose-600">{{ __('storefront.out_of_stock') }}</p>
        @endunless
    </div>
</a>
