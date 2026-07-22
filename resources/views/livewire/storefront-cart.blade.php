<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">{{ __('storefront.shopping_cart') }}</h1>

        @if ($lines->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center">
                <p class="text-[#6B6459] mb-4">{{ __('storefront.cart_empty') }}</p>
                <a href="{{ route('home') }}#collection" wire:navigate
                   class="inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                    {{ __('storefront.continue_shopping') }}
                </a>
            </div>
        @else
            <div class="grid lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-4">
                    @foreach ($lines as $line)
                        @php $product = $line['product']; @endphp
                        <div class="flex gap-4 rounded-xl border border-[#EFE7D6] bg-white p-4" wire:key="cart-line-{{ $product->id }}">
                            @if ($image = \App\Support\StorefrontAssets::url($product->primaryImagePath()))
                                <img src="{{ $image }}" alt="" class="w-20 h-20 rounded-lg object-cover bg-[#F1EADB] shrink-0">
                            @endif
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('product.show', $product) }}" wire:navigate class="font-medium hover:text-[#C9A227] line-clamp-2">{{ $product->name }}</a>
                                <p class="text-sm text-[#8C8474] mt-1">&#2547; {{ number_format($product->price, 0) }} {{ __('storefront.each') }}</p>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <input type="number" min="1" value="{{ $line['quantity'] }}"
                                        wire:change="updateQuantity({{ $product->id }}, $event.target.value)"
                                        class="w-16 rounded-lg border border-[#E0D6C2] px-2 py-1 text-sm">
                                    <button type="button" wire:click="remove({{ $product->id }})" class="text-sm text-rose-600 hover:underline">{{ __('storefront.remove') }}</button>
                                </div>
                            </div>
                            <div class="text-right font-semibold shrink-0">
                                &#2547; {{ number_format($line['line_total'], 0) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 h-fit">
                    <h2 class="font-semibold mb-4">{{ __('storefront.order_summary') }}</h2>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-[#6B6459]">{{ __('storefront.subtotal') }}</span>
                        <span>&#2547; {{ number_format($subtotal, 0) }}</span>
                    </div>
                    <p class="text-xs text-[#8C8474] mb-4">{{ __('storefront.delivery_discounts_checkout') }}</p>
                    <div class="border-t border-[#E7DFCF] pt-4 flex justify-between font-semibold text-lg mb-6">
                        <span>{{ __('storefront.estimated') }}</span>
                        <span>&#2547; {{ number_format($subtotal, 0) }}</span>
                    </div>
                    <a href="{{ route('checkout') }}" wire:navigate
                        class="block w-full text-center rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                        {{ __('storefront.proceed_checkout') }}
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-storefront.shell>
