<x-storefront.shell>
    <x-seo.json-ld :data="\App\Support\JsonLd::categoryBreadcrumb($category)" />

    <div class="mx-auto max-w-6xl px-4 py-8">
        <nav class="text-xs text-[#8C8474] mb-4" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.breadcrumb_home') }}</a>
            <span class="mx-2">/</span>
            <span class="text-[#1E1E1E]">{{ $category->name }}</span>
        </nav>

        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
            <div>
                <h1 class="font-serif text-3xl font-semibold">{{ $category->name }}</h1>
                @if ($category->headline)
                    <p class="mt-2 text-[#6B6459] max-w-2xl">{{ $category->headline }}</p>
                @endif
                <p class="mt-1 text-sm text-[#8C8474]">{{ __('storefront.products_count', ['count' => $products->total()]) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-[#6B6459] cursor-pointer select-none">
                    <input type="checkbox" wire:model.live="inStockOnly"
                        class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                    {{ __('storefront.in_stock_only') }}
                </label>
                @if (! empty($inStockOnly))
                    <button type="button" wire:click="$set('inStockOnly', false)"
                        class="text-sm text-[#C9A227] hover:underline">
                        {{ __('storefront.clear_filters') }}
                    </button>
                @endif
                <div>
                    <label for="sort" class="sr-only">{{ __('storefront.sort_products') }}</label>
                    <select id="sort" wire:model.live="sort"
                        class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        <option value="featured">{{ __('storefront.sort_featured') }}</option>
                        <option value="newest">{{ __('storefront.sort_newest') }}</option>
                        <option value="price_asc">{{ __('storefront.sort_price_asc') }}</option>
                        <option value="price_desc">{{ __('storefront.sort_price_desc') }}</option>
                    </select>
                </div>
            </div>
        </div>

        @if ($products->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                @if (! empty($inStockOnly))
                    {{ __('storefront.no_products_filtered') }}
                @else
                    {{ __('storefront.no_products_category') }}
                @endif
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
