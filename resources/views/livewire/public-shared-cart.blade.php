<x-storefront.shell>
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <header class="mb-6 flex items-center justify-between gap-3 border-b border-[#E7DFCF] pb-4">
            <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 min-w-0">
                <img src="/img/settings/logo.png" alt="Sundoritoma" class="h-10 w-auto object-contain">
                <span class="font-serif text-lg font-semibold truncate">Sundoritoma</span>
            </a>
            <a href="{{ config('seo.whatsapp_url') }}" target="_blank" rel="noopener noreferrer" class="shrink-0 text-sm font-medium text-[#7A6114] hover:underline">
                {{ __('storefront.helpline_label') }}: {{ config('seo.whatsapp_display') }}
            </a>
        </header>

        @if ($expired)
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center">
                <h1 class="font-serif text-2xl font-semibold">{{ __('storefront.shared_cart_expired_title') }}</h1>
                <p class="mt-2 text-sm text-[#6B6459]">{{ __('storefront.shared_cart_expired_body') }}</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('home') }}" wire:navigate
                       class="inline-block rounded-full bg-[#8F7218] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#7A6114]">
                        {{ __('storefront.go_home') }}
                    </a>
                    <a href="{{ config('seo.whatsapp_url') }}" target="_blank" rel="noopener noreferrer"
                       class="inline-block rounded-full border border-[#C9A227] px-6 py-2.5 text-sm font-semibold text-[#7A6114] hover:bg-[#FAF6EF]">
                        {{ __('storefront.call_us') }}
                    </a>
                </div>
            </div>
        @else
            <div class="mb-4">
                <h1 class="font-serif text-2xl font-semibold">{{ __('storefront.shared_cart_title') }}</h1>
                <p class="mt-1 text-sm text-[#6B6459]">{{ __('storefront.shared_cart_purpose') }}</p>
                @if ($sharedCart?->expires_at)
                    <p class="mt-1 text-sm text-[#5C564C]">
                        {{ __('storefront.share_valid_until', ['date' => $sharedCart->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A')]) }}
                    </p>
                @endif
            </div>

            @if ($lines->isEmpty())
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center">
                    <p class="text-sm text-[#5C564C]">{{ __('storefront.shared_cart_empty') }}</p>
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <a href="{{ route('home') }}" wire:navigate
                           class="inline-block rounded-full bg-[#8F7218] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#7A6114]">
                            {{ __('storefront.go_home') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="space-y-4 mb-6">
                    @foreach ($lines as $line)
                        @php $product = $line['product']; @endphp
                        <div wire:key="shared-cart-line-{{ $product->id }}"
                            class="flex gap-4 rounded-xl border border-[#EFE7D6] bg-white p-4">
                            @if ($image = \App\Support\StorefrontAssets::url($product->primaryImagePath()))
                                <img src="{{ $image }}" alt="" class="w-20 h-20 rounded-lg object-cover bg-[#F1EADB] shrink-0">
                            @endif
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('product.show', $product) }}" wire:navigate class="font-medium hover:text-[#7A6114] line-clamp-2">{{ $product->name }}</a>
                                <div class="mt-1">
                                    <x-storefront.product-price :product="$product" size="sm" />
                                </div>
                                <p class="mt-2 text-sm text-[#6B6459]">{{ __('storefront.quantity') }}: {{ $line['quantity'] }}</p>
                            </div>
                            <div class="text-right font-semibold shrink-0">
                                &#2547; {{ number_format($line['line_total'], 0) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">{{ __('storefront.order_summary') }}</h2>
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-[#6B6459]">{{ __('storefront.subtotal') }} ({{ __('storefront.item_count', ['count' => $itemCount]) }})</span>
                        <span>&#2547; {{ number_format($subtotal, 0) }}</span>
                    </div>
                    <p class="text-xs text-[#5C564C] mb-4">{{ __('storefront.delivery_discounts_checkout') }}</p>
                    <div class="border-t border-[#E7DFCF] pt-4 flex justify-between font-semibold text-lg mb-6">
                        <span>{{ __('storefront.estimated') }}</span>
                        <span>&#2547; {{ number_format($subtotal, 0) }}</span>
                    </div>
                    <button type="button"
                        wire:click="proceedToCheckout"
                        wire:loading.attr="disabled"
                        class="block w-full text-center rounded-full bg-[#8F7218] px-8 py-3 text-sm font-semibold text-white hover:bg-[#7A6114] transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="proceedToCheckout">{{ __('storefront.proceed_checkout') }}</span>
                        <span wire:loading wire:target="proceedToCheckout">{{ __('storefront.loading') }}…</span>
                    </button>
                </div>
            @endif
        @endif
    </div>
</x-storefront.shell>
