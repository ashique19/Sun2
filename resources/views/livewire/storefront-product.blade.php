<x-storefront.shell>
    @php
        $images = $product->images;
        $active = $images[$activeImage] ?? $images->first();
        $activeUrl = $active ? \App\Support\StorefrontAssets::url($active->path) : null;
        $descriptionHtml = $product->description_bn ?: $product->description;
    @endphp

    <x-seo.json-ld :data="\App\Support\JsonLd::product($product)" />
    <x-seo.json-ld :data="\App\Support\JsonLd::productBreadcrumb($product)" />

    <div class="mx-auto max-w-6xl px-4 py-8 pb-24 lg:pb-0">
        <nav class="text-xs text-[#8C8474] mb-4" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.breadcrumb_home') }}</a>
            @if ($product->category)
                <span class="mx-2">/</span>
                <a href="{{ route('category.show', $product->category) }}" wire:navigate class="hover:text-[#C9A227]">{{ $product->category->name }}</a>
            @endif
            <span class="mx-2">/</span>
            <span class="text-[#1E1E1E] line-clamp-1">{{ $product->name }}</span>
        </nav>

        <div class="grid lg:grid-cols-2 gap-10">
            <div>
                <div class="rounded-xl overflow-hidden bg-white border border-[#EFE7D6] aspect-square">
                    @if ($activeUrl)
                        <img src="{{ $activeUrl }}" alt="{{ $product->name }}" class="w-full h-full object-cover" fetchpriority="high">
                    @else
                        <div class="w-full h-full bg-[#F1EADB] flex items-center justify-center text-5xl text-[#C9A227]">&#9670;</div>
                    @endif
                </div>
                @if ($images->count() > 1)
                    <div class="mt-4 grid grid-cols-5 gap-2">
                        @foreach ($images as $index => $image)
                            @if ($thumb = \App\Support\StorefrontAssets::smallUrl($image->path) ?? \App\Support\StorefrontAssets::url($image->path))
                                <button type="button" wire:click="selectImage({{ $index }})"
                                    class="rounded-lg overflow-hidden border-2 aspect-square {{ $activeImage === $index ? 'border-[#C9A227]' : 'border-transparent' }}">
                                    <img src="{{ $thumb }}" alt="" class="w-full h-full object-cover" loading="lazy" decoding="async">
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                @if ($product->category)
                    <p class="text-xs uppercase tracking-wider text-[#C9A227] mb-2">{{ $product->category->name }}</p>
                @endif
                <h1 class="font-serif text-3xl font-semibold leading-tight">{{ $product->name }}</h1>

                <div class="mt-4 flex items-baseline gap-3">
                    <span class="text-2xl font-semibold">&#2547; {{ number_format($product->price, 0) }}</span>
                    @if ($product->compare_at_price && $product->compare_at_price > $product->price)
                        <span class="text-[#8C8474] line-through">&#2547; {{ number_format($product->compare_at_price, 0) }}</span>
                    @endif
                </div>

                @if ($product->review_count > 0)
                    <div class="mt-2 flex items-center gap-2 text-sm text-[#6B6459]">
                        <span class="text-[#C9A227]">
                            @for ($i = 1; $i <= 5; $i++)
                                {{ $i <= round($product->rating_avg) ? '★' : '☆' }}
                            @endfor
                        </span>
                        <span>{{ number_format($product->rating_avg, 1) }} ({{ $product->review_count }})</span>
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    @if ($product->is_new)
                        <span class="rounded-full bg-[#F1EADB] px-3 py-1 text-[#6B6459]">{{ __('storefront.new_badge') }}</span>
                    @endif
                    @if ($product->is_best_seller)
                        <span class="rounded-full bg-[#F1EADB] px-3 py-1 text-[#6B6459]">{{ __('storefront.best_seller') }}</span>
                    @endif
                    @if ($product->isInStock())
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{{ __('storefront.in_stock') }}</span>
                    @else
                        <span class="rounded-full bg-rose-50 px-3 py-1 text-rose-700">{{ __('storefront.out_of_stock') }}</span>
                    @endif
                </div>

                @if ($descriptionHtml)
                    <div class="product-description mt-6 text-[#6B6459]">
                        {!! $descriptionHtml !!}
                    </div>
                @else
                    <p class="mt-6 text-[#6B6459] leading-relaxed">
                        {{ __('storefront.handmade_tagline') }}
                    </p>
                @endif

                <div class="mt-8 hidden lg:flex flex-wrap items-center gap-4">
                    <div class="flex items-center rounded-full border border-[#E0D6C2] overflow-hidden">
                        <button type="button" wire:click="$set('quantity', max(1, quantity - 1))"
                            class="px-3 py-2 hover:bg-[#F1EADB]">−</button>
                        <span class="px-4 py-2 text-sm font-medium min-w-[2.5rem] text-center">{{ $quantity }}</span>
                        <button type="button" wire:click="$set('quantity', quantity + 1)"
                            class="px-3 py-2 hover:bg-[#F1EADB]">+</button>
                    </div>
                    <button type="button" wire:click="addToCart"
                        @disabled(! $product->isInStock())
                        class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ __('storefront.add_to_cart') }}
                    </button>
                    <button type="button" wire:click="toggleWishlist"
                        class="inline-flex items-center gap-2 rounded-full border border-[#E0D6C2] px-4 py-3 text-sm hover:bg-[#FAF6EF] transition"
                        title="{{ $isWishlisted ? __('storefront.saved') : __('storefront.save') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="{{ $isWishlisted ? 'currentColor' : 'none' }}" aria-hidden="true" class="{{ $isWishlisted ? 'text-[#C9A227]' : 'text-[#6B6459]' }}">
                            <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7-4.35-7-9.2A3.8 3.8 0 0 1 12 7.5a3.8 3.8 0 0 1 7 3.3C19 15.65 12 20 12 20Z"/>
                        </svg>
                        <span>{{ $isWishlisted ? __('storefront.saved') : __('storefront.save') }}</span>
                    </button>
                </div>

                @if ($addedMessage)
                    <p class="mt-3 text-sm text-emerald-700">{{ __('storefront.added_to_cart') }}
                        <a href="{{ route('cart') }}" wire:navigate class="underline">{{ __('storefront.view_cart') }}</a>
                    </p>
                @endif

                <div class="mt-8 rounded-xl border border-[#E7DFCF] bg-white p-4 text-sm text-[#6B6459] space-y-1">
                    <p>&#10003; {{ __('storefront.cash_on_delivery') }}</p>
                    <p>&#10003; {{ __('storefront.free_delivery_dhaka') }}</p>
                    <p>&#10003; {{ __('storefront.helpline_with_number') }}</p>
                </div>
            </div>
        </div>

        <div class="mt-16 grid lg:grid-cols-2 gap-10">
            <div>
                <h2 class="font-serif text-2xl font-semibold mb-6">{{ __('storefront.customer_reviews') }}</h2>
                @if ($product->approvedReviews->isEmpty())
                    <p class="text-sm text-[#8C8474]">{{ __('storefront.no_reviews') }}</p>
                @else
                    <div class="space-y-4">
                        @foreach ($product->approvedReviews as $review)
                            <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span class="font-medium text-sm">{{ $review->user?->name ?? __('storefront.account') }}</span>
                                    <span class="text-[#C9A227] text-sm">
                                        @for ($i = 1; $i <= 5; $i++){{ $i <= $review->rating ? '★' : '☆' }}@endfor
                                    </span>
                                </div>
                                @if ($review->title)
                                    <p class="font-medium text-sm mb-1">{{ $review->title }}</p>
                                @endif
                                <p class="text-sm text-[#6B6459] leading-relaxed">{{ $review->body }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <h2 class="font-serif text-2xl font-semibold mb-6">{{ __('storefront.write_review') }}</h2>
                @auth
                    @if ($reviewMessage)
                        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ __('storefront.review_thanks') }}</div>
                    @endif
                    @if ($reviewError)
                        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ __('storefront.already_reviewed') }}</div>
                    @endif
                    <form wire:submit="submitReview" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">{{ __('storefront.rating') }}</label>
                            <select wire:model="reviewRating" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                @for ($r = 5; $r >= 1; $r--)
                                    <option value="{{ $r }}">{{ $r }}</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">{{ __('storefront.review_title_optional') }}</label>
                            <input type="text" wire:model="reviewTitle" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">{{ __('storefront.your_review') }}</label>
                            <textarea wire:model="reviewBody" rows="4" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm"></textarea>
                            @error('reviewBody') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                            {{ __('storefront.submit_review') }}
                        </button>
                    </form>
                @else
                    <p class="text-sm text-[#6B6459]">
                        <a href="{{ route('login') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.login') }}</a>
                        — {{ __('storefront.login_to_review') }}
                    </p>
                @endauth
            </div>
        </div>
    </div>

    {{-- Sticky mobile buy bar --}}
    <div class="lg:hidden fixed bottom-16 inset-x-0 z-20 border-t border-[#E7DFCF] bg-white/95 backdrop-blur"
         style="padding-bottom: env(safe-area-inset-bottom, 0);">
        <div class="mx-auto max-w-6xl px-4 py-3 flex items-center gap-3">
            <div class="flex items-center rounded-full border border-[#E0D6C2] overflow-hidden shrink-0">
                <button type="button" wire:click="$set('quantity', max(1, quantity - 1))"
                    class="px-3 py-2 hover:bg-[#F1EADB]">−</button>
                <span class="px-3 py-2 text-sm font-medium min-w-[2rem] text-center">{{ $quantity }}</span>
                <button type="button" wire:click="$set('quantity', quantity + 1)"
                    class="px-3 py-2 hover:bg-[#F1EADB]">+</button>
            </div>
            <button type="button" wire:click="addToCart"
                @disabled(! $product->isInStock())
                class="flex-1 rounded-full bg-[#C9A227] px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition disabled:opacity-50 disabled:cursor-not-allowed">
                {{ __('storefront.add_to_cart') }}
            </button>
            <button type="button" wire:click="toggleWishlist"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] hover:bg-[#FAF6EF]"
                title="{{ $isWishlisted ? __('storefront.saved') : __('storefront.save') }}"
                aria-label="{{ $isWishlisted ? __('storefront.saved') : __('storefront.save') }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="{{ $isWishlisted ? 'currentColor' : 'none' }}" aria-hidden="true" class="{{ $isWishlisted ? 'text-[#C9A227]' : 'text-[#6B6459]' }}">
                    <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7-4.35-7-9.2A3.8 3.8 0 0 1 12 7.5a3.8 3.8 0 0 1 7 3.3C19 15.65 12 20 12 20Z"/>
                </svg>
            </button>
        </div>
    </div>
</x-storefront.shell>
