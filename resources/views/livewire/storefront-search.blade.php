<x-storefront.shell :query="$q">
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-2">
            @if ($q !== '')
                Results for “{{ $q }}”
            @else
                Search Products
            @endif
        </h1>
        <p class="text-sm text-[#8C8474] mb-8">{{ $products->total() }} products found</p>

        @if ($products->isEmpty())
            <div class="rounded-xl border border-dashed border-[#D8CDB6] p-10 text-center text-[#6B6459]">
                @if ($q === '')
                    Enter a search term above to find handmade jewellery.
                @else
                    No products matched your search. Try a different keyword.
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
