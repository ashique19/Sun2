<div>
    {{-- Top announcement bar --}}
    <div class="bg-[#1E1E1E] text-center text-xs tracking-wide text-[#E9C978] py-2">
        Free delivery inside Dhaka &middot; Cash on Delivery available
    </div>

    {{-- Header --}}
    <header class="border-b border-[#E7DFCF] bg-[#FAF6EF]/90 backdrop-blur sticky top-0 z-10">
        <div class="mx-auto max-w-6xl px-4 py-4 flex items-center gap-4">
            <a href="/" class="font-serif text-2xl font-semibold text-[#1E1E1E]">
                SUNDORI<span class="text-[#C9A227]">TOMA</span>
            </a>
            <div class="flex-1">
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="Search categories…"
                    class="w-full rounded-full border border-[#E0D6C2] bg-white px-5 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
            </div>
            <div class="flex items-center gap-4 text-[#1E1E1E]">
                <span title="Wishlist">&#9825;</span>
                <span title="Cart">&#128722;</span>
            </div>
        </div>
    </header>

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-14 text-center">
        <p class="text-[#C9A227] uppercase tracking-[0.25em] text-xs mb-4">Traditional &amp; Imitation Jewelry</p>
        <h1 class="font-serif text-4xl md:text-5xl font-semibold leading-tight">Sparkle with Tradition</h1>
        <p class="mt-4 text-[#6B6459] max-w-xl mx-auto">
            Premium German silver, brass &amp; exclusive beads — handcrafted for every occasion.
        </p>
        <a href="#collection"
           class="mt-8 inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#b8931f] transition">
            Shop Collection
        </a>
    </section>

    {{-- Stack status banner (proves Livewire + Tailwind + DB are wired up) --}}
    <div class="mx-auto max-w-6xl px-4">
        <div class="rounded-xl border border-[#E7DFCF] bg-white p-4 text-sm text-[#6B6459] flex flex-wrap items-center gap-x-6 gap-y-1">
            <span class="font-semibold text-[#1E1E1E]">Stack check:</span>
            <span>Laravel {{ app()->version() }}</span>
            <span>Livewire {{ \Composer\InstalledVersions::getPrettyVersion('livewire/livewire') }}</span>
            <span>Tailwind v4</span>
            <span>Categories in DB: <strong class="text-[#C9A227]">{{ $categories->count() }}</strong></span>
        </div>
    </div>

    {{-- Categories grid --}}
    <section id="collection" class="mx-auto max-w-6xl px-4 py-12">
        <h2 class="font-serif text-2xl font-semibold mb-6">Shop by Category</h2>

        @if ($categories->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                No categories yet. Production data will be imported here later.
            </div>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                @foreach ($categories as $category)
                    <div class="group rounded-xl bg-white border border-[#EFE7D6] overflow-hidden hover:shadow-md transition">
                        <div class="aspect-square bg-[#F1EADB] flex items-center justify-center text-4xl text-[#C9A227]">
                            &#9670;
                        </div>
                        <div class="p-4 text-center">
                            <h3 class="font-medium">{{ $category->name }}</h3>
                            <p class="text-xs text-[#8C8474] mt-1">{{ $category->products()->count() }} products</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <footer class="border-t border-[#E7DFCF] py-8 text-center text-xs text-[#8C8474]">
        &copy; {{ date('Y') }} Sundoritoma &middot; Rebuilt on Laravel {{ app()->version() }} + Livewire + Tailwind v4
    </footer>
</div>
