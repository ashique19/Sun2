<x-storefront.shell :query="$q">
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-2">
            @if ($q !== '')
                {{ __('storefront.results_for', ['q' => $q]) }}
            @else
                {{ __('storefront.search_products') }}
            @endif
        </h1>
        <p class="text-sm text-[#5C564C] mb-8">{{ __('storefront.products_found', ['count' => $products->total()]) }}</p>

        @if ($products->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                @if ($q === '')
                    <p>{{ __('storefront.search_empty_hint') }}</p>
                @else
                    <p>{{ __('storefront.search_no_match') }}</p>
                @endif
                <div class="mt-6 flex flex-wrap items-center justify-center gap-4 text-sm">
                    <a href="{{ route('home') }}#collection" wire:navigate class="text-[#7A6114] hover:underline">
                        {{ __('storefront.popular_categories') }}
                    </a>
                    <a href="{{ route('shop') }}" wire:navigate class="text-[#7A6114] hover:underline">
                        {{ __('storefront.view_all') }}
                    </a>
                </div>
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
