<div>
    <x-storefront.announcement />
    <x-storefront.header />

    @if ($heroSlides->isNotEmpty())
        <section
            x-data="{
                active: 0,
                total: {{ $heroSlides->count() }},
                timer: null,
                start() { this.timer = setInterval(() => { this.active = (this.active + 1) % this.total; }, 6000); },
                stop() { clearInterval(this.timer); },
                goTo(index) { this.active = index; },
            }"
            x-init="start()"
            @mouseenter="stop()"
            @mouseleave="start()"
            class="relative overflow-hidden bg-[#1E1E1E]"
        >
            <div class="relative w-full min-h-[280px] md:min-h-[420px] max-h-[520px]">
                @foreach ($heroSlides as $index => $slide)
                    <div
                        x-show="active === {{ $index }}"
                        x-transition:enter="transition ease-out duration-700"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-500"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0"
                        @if ($index > 0) style="display: none;" @endif
                    >
                        @if ($image = \App\Support\StorefrontAssets::url($slide->image))
                            <img src="{{ $image }}" alt="{{ $slide->title }}" class="h-full w-full object-cover">
                        @endif
                        <div class="absolute inset-0 bg-gradient-to-r from-black/60 via-black/30 to-transparent"></div>
                        <div class="absolute inset-0 flex items-center">
                            <div class="mx-auto w-full max-w-6xl px-6 md:px-10">
                                <p class="text-[#E9C978] uppercase tracking-[0.25em] text-xs mb-3">Traditional &amp; Imitation Jewelry</p>
                                <h1 class="font-serif text-3xl md:text-5xl font-semibold leading-tight text-white max-w-xl">{{ $slide->title }}</h1>
                                @if ($slide->subtitle)
                                    <p class="mt-3 text-sm md:text-base text-white/80 max-w-lg">{{ $slide->subtitle }}</p>
                                @endif
                                <a href="{{ $slide->link_url ?: '#collection' }}"
                                   class="mt-6 inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition">
                                    {{ $slide->link_label ?? 'Shop Now' }}
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($heroSlides->count() > 1)
                <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
                    @foreach ($heroSlides as $index => $slide)
                        <button type="button" @click="goTo({{ $index }})"
                            :class="active === {{ $index }} ? 'bg-[#C9A227] w-8' : 'bg-white/50 w-2'"
                            class="h-2 rounded-full transition-all duration-300"
                            aria-label="Go to slide {{ $index + 1 }}"></button>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    <section id="collection" class="mx-auto max-w-6xl px-4 py-12">
        <div class="flex items-end justify-between gap-4 mb-6">
            <h2 class="font-serif text-2xl font-semibold">Shop by Category</h2>
            <a href="{{ route('search') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">View all products</a>
        </div>

        @if ($categories->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                No categories yet.
            </div>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                @foreach ($categories as $category)
                    <a href="{{ route('category.show', $category) }}" wire:navigate
                       class="group rounded-xl bg-white border border-[#EFE7D6] overflow-hidden hover:shadow-md transition">
                        @if ($image = \App\Support\StorefrontAssets::url($category->thumb_image))
                            <img src="{{ $image }}" alt="{{ $category->name }}"
                                class="aspect-square w-full object-cover bg-[#F1EADB] group-hover:scale-[1.02] transition-transform duration-300">
                        @else
                            <div class="aspect-square bg-[#F1EADB] flex items-center justify-center text-4xl text-[#C9A227]">&#9670;</div>
                        @endif
                        <div class="p-4 text-center">
                            <h3 class="font-medium group-hover:text-[#C9A227] transition">{{ $category->name }}</h3>
                            @if ($category->headline)
                                <p class="text-xs text-[#8C8474] mt-1 line-clamp-2">{{ $category->headline }}</p>
                            @endif
                            <p class="text-xs text-[#8C8474] mt-1">{{ $category->products_count }} products</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <x-storefront.footer />
</div>
