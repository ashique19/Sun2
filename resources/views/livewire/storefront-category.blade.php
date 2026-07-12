<x-storefront.shell>
    <x-seo.json-ld :data="\App\Support\JsonLd::categoryBreadcrumb($category)" />

    <div class="mx-auto max-w-6xl px-4 py-8">
        <nav class="text-xs text-[#8C8474] mb-4" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" wire:navigate class="hover:text-[#C9A227]">Home</a>
            <span class="mx-2">/</span>
            <span class="text-[#1E1E1E]">{{ $category->name }}</span>
        </nav>

        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
            <div>
                <h1 class="font-serif text-3xl font-semibold">{{ $category->name }}</h1>
                @if ($category->headline)
                    <p class="mt-2 text-[#6B6459] max-w-2xl">{{ $category->headline }}</p>
                @endif
                <p class="mt-1 text-sm text-[#8C8474]">{{ $products->total() }} products</p>
            </div>
            <div>
                <label for="sort" class="sr-only">Sort products</label>
                <select id="sort" wire:model.live="sort"
                    class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    <option value="featured">Featured</option>
                    <option value="newest">Newest</option>
                    <option value="price_asc">Price: Low to High</option>
                    <option value="price_desc">Price: High to Low</option>
                </select>
            </div>
        </div>

        @if ($products->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                No products in this category yet.
            </div>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                @foreach ($products as $product)
                    <x-storefront.product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-8">{{ $products->links() }}</div>
        @endif
    </div>
</x-storefront.shell>
