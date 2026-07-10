<div>
    <x-storefront.announcement />
    <x-storefront.header />

    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-2">Wishlist</h1>
        <p class="text-sm text-[#8C8474] mb-8">{{ $items->count() }} saved item(s)</p>

        @if ($message)
            <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-6">{{ $message }}</div>
        @endif

        @if ($items->isEmpty())
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-10 text-center">
                <p class="text-[#6B6459] mb-4">Your wishlist is empty.</p>
                <a href="{{ route('home') }}" wire:navigate
                    class="inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f]">
                    Browse Products
                </a>
            </div>
        @else
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($items as $product)
                    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
                        <a href="{{ route('product.show', $product) }}" wire:navigate>
                            @php $img = $product->primaryImagePath() @endphp
                            <div class="aspect-square bg-[#F1EADB]">
                                @if ($img)
                                    <img src="{{ \App\Support\StorefrontAssets::url($img) }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                                @endif
                            </div>
                        </a>
                        <div class="p-4">
                            <a href="{{ route('product.show', $product) }}" wire:navigate class="font-medium line-clamp-2 hover:text-[#C9A227]">{{ $product->name }}</a>
                            <p class="mt-1 font-semibold">&#2547; {{ number_format($product->price, 0) }}</p>
                            <div class="mt-4 flex gap-2">
                                <button type="button" wire:click="addToCart({{ $product->id }})"
                                    class="flex-1 rounded-full bg-[#C9A227] px-4 py-2 text-xs font-semibold text-white hover:bg-[#b8931f]">
                                    Add to Cart
                                </button>
                                <button type="button" wire:click="remove({{ $product->id }})"
                                    class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs hover:bg-[#FAF6EF]">
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <x-storefront.footer />
</div>
