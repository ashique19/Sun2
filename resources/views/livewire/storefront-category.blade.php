<x-storefront.shell>
    <x-seo.json-ld :data="\App\Support\JsonLd::categoryBreadcrumb($category)" />

    <div class="mx-auto max-w-6xl px-4 py-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <nav class="min-w-0 text-xs text-[#5C564C]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" wire:navigate class="hover:text-[#7A6114]">{{ __('storefront.breadcrumb_home') }}</a>
                <span class="mx-2">/</span>
                <span class="text-[#1E1E1E]">{{ $category->name }}</span>
            </nav>

            <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs text-[#6B6459]">
                <label class="inline-flex cursor-pointer select-none items-center gap-1.5">
                    <input type="checkbox" wire:model.live="inStockOnly"
                        class="size-3.5 rounded border-[#E0D6C2] text-[#7A6114] focus:ring-[#8F7218]">
                    {{ __('storefront.in_stock_only') }}
                </label>
                @if (! empty($inStockOnly))
                    <button type="button" wire:click="$set('inStockOnly', false)"
                        class="text-xs text-[#7A6114] hover:underline">
                        {{ __('storefront.clear_filters') }}
                    </button>
                @endif
                <div>
                    <label for="sort" class="sr-only">{{ __('storefront.sort_products') }}</label>
                    <select id="sort" wire:model.live="sort"
                        class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-xs focus:border-[#8F7218] focus:outline-none focus:ring-1 focus:ring-[#8F7218]">
                        <option value="featured">{{ __('storefront.sort_featured') }}</option>
                        <option value="newest">{{ __('storefront.sort_newest') }}</option>
                        <option value="price_asc">{{ __('storefront.sort_price_asc') }}</option>
                        <option value="price_desc">{{ __('storefront.sort_price_desc') }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-6">
            <h1 class="font-serif text-3xl font-semibold">{{ $category->name }}</h1>
            @if ($category->headline)
                <p class="mt-2 max-w-2xl text-[#6B6459]">{{ $category->headline }}</p>
            @endif
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
            <div class="grid grid-cols-2 gap-5 md:grid-cols-4">
                @foreach ($products as $product)
                    <x-storefront.product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-8">{{ $products->links() }}</div>
        @endif
    </div>
</x-storefront.shell>
