<x-storefront.shell>
    @if ($heroSlides->isNotEmpty())
        @php $slide = $heroSlides->first(); @endphp
        <section class="relative overflow-hidden bg-[#1E1E1E]">
            <div class="relative w-full min-h-[220px] sm:min-h-[280px] md:min-h-[420px] max-h-[520px]">
                <div class="absolute inset-0">
                    @if ($image = \App\Support\StorefrontAssets::mediumUrl($slide->image) ?? \App\Support\StorefrontAssets::url($slide->image))
                        <img
                            src="{{ $image }}"
                            alt="{{ $slide->title }}"
                            class="h-full w-full object-cover"
                            width="1200"
                            height="520"
                            fetchpriority="high"
                        >
                    @endif
                    <div class="absolute inset-0 bg-gradient-to-r from-black/60 via-black/30 to-transparent"></div>
                    <div class="absolute inset-0 flex items-center">
                        <div class="mx-auto w-full max-w-6xl px-6 md:px-10">
                            <h1 class="font-serif text-3xl md:text-5xl font-semibold leading-tight text-white max-w-xl">{{ $slide->title }}</h1>
                            @if ($slide->subtitle)
                                <p class="mt-3 text-sm md:text-base text-white/80 max-w-lg">{{ $slide->subtitle }}</p>
                            @endif
                            <a href="{{ $slide->link_url ?: '#collection' }}"
                               class="mt-6 inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition">
                                {{ $slide->link_label ?: __('storefront.shop_now') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @else
        <div class="mx-auto max-w-6xl px-4 pt-10 text-center sm:text-left">
            <img src="/img/settings/logo.png" alt="Sundoritoma" class="mx-auto sm:mx-0 h-16 w-auto object-contain mb-4" width="192" height="64">
            <h1 class="font-serif text-3xl md:text-4xl font-semibold">{{ __('storefront.handmade_jewellery') }}</h1>
            <p class="mt-2 text-[#6B6459] max-w-2xl mx-auto sm:mx-0">{{ __('storefront.handmade_tagline') }}</p>
            <a href="#collection"
               class="mt-6 inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition">
                {{ __('storefront.shop_by_category') }}
            </a>
        </div>
    @endif

    <section id="collection" class="mx-auto max-w-6xl px-4 py-12">
        <div class="flex items-end justify-between gap-4 mb-6">
            <h2 class="font-serif text-2xl font-semibold">{{ __('storefront.shop_by_category') }}</h2>
            <a href="{{ route('search') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">{{ __('storefront.view_all_products') }}</a>
        </div>

        @if ($categories->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                {{ __('storefront.no_categories_yet') }}
            </div>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                @foreach ($categories as $category)
                    <a href="{{ route('category.show', $category) }}" wire:navigate
                       class="group rounded-xl bg-white border border-[#EFE7D6] overflow-hidden hover:shadow-md transition">
                        @if ($category->thumb_image)
                            <x-storefront.listing-image
                                :path="$category->thumb_image"
                                :alt="$category->name"
                                sizes="(max-width: 768px) 50vw, 25vw"
                                class="aspect-square w-full object-cover bg-[#F1EADB] group-hover:scale-[1.02] transition-transform duration-300"
                            />
                        @else
                            <div class="aspect-square bg-[#F1EADB] flex items-center justify-center text-4xl text-[#C9A227]">&#9670;</div>
                        @endif
                        <div class="p-4 text-center">
                            <h3 class="font-medium group-hover:text-[#C9A227] transition">{{ $category->name }}</h3>
                            @if ($category->headline)
                                <p class="text-xs text-[#8C8474] mt-1 line-clamp-2">{{ $category->headline }}</p>
                            @endif
                            <p class="text-xs text-[#8C8474] mt-1">{{ __('storefront.products_count', ['count' => $category->products_count]) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-storefront.shell>
