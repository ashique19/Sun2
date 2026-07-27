<x-storefront.shell>
    @php
        /** @var \App\Models\SocialPost $post */
        $thumb = $post->thumbnail_path ?: $post->products->first()?->primaryImagePath();
    @endphp

    <div class="mx-auto max-w-6xl px-4 py-10">
        <div class="mb-6">
            <h1 class="font-serif text-3xl font-semibold">Latest post</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Hand-picked products · Published on {{ $post->created_at->toFormattedDateString() }}</p>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
            @if ($post->layout === 'collage' && $post->collage_path)
                <img src="{{ \App\Support\StorefrontAssets::url($post->collage_path) }}" alt="" class="w-full max-h-[460px] object-cover">
            @elseif ($thumb)
                <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="w-full max-h-[460px] object-cover">
            @endif

            <div class="p-5">
                <div class="whitespace-pre-wrap text-sm leading-relaxed text-[#1E1E1E]">
                    {{ $post->body }}
                </div>

                @if ($facebookPub && $facebookPub->external_url)
                    <div class="mt-4">
                        <a href="{{ $facebookPub->external_url }}" target="_blank" rel="noreferrer"
                            class="inline-flex items-center rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                            View on Facebook
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-10">
            <h2 class="font-semibold text-lg mb-3">Products in this post</h2>
            @if ($post->products->isEmpty())
                <div class="text-sm text-[#8C8474]">No products attached.</div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @foreach ($post->products as $product)
                        @php $pThumb = $product->primaryImagePath(); @endphp
                        <a href="{{ route('product.show', $product) }}" wire:navigate
                            class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden hover:shadow-sm transition">
                            <div class="aspect-square bg-[#F1EADB] flex items-center justify-center overflow-hidden">
                                @if ($pThumb)
                                    <img src="{{ \App\Support\StorefrontAssets::url($pThumb) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <span class="text-[#C9A227] text-3xl">&#9670;</span>
                                @endif
                            </div>
                            <div class="p-3 text-center">
                                <div class="text-sm font-medium line-clamp-1">{{ $product->name }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($primaryCategory)
            <div class="mt-10">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold text-lg">See more similar products</h2>
                    <a href="{{ route('category.show', $primaryCategory) }}" wire:navigate
                        class="text-sm font-semibold text-[#C9A227] hover:underline">
                        {{ $primaryCategory->name }} &rarr;
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-storefront.shell>

