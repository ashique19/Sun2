<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Social Post #{{ $post->id }}</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Compose → publish → re-publish via Meta Graph.</p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('admin.products') }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                Back
            </a>
            <button type="button" wire:click="republish"
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                Re-publish
            </button>
        </div>
    </div>

    @if ($message)
        <div class="mb-4 rounded-xl border border-[#EFE7D6] bg-white p-4 text-sm text-[#6B6459]">
            {{ $message }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    {{ ucfirst((string) $post->image_source) }}
                </span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    {{ ucfirst((string) $post->layout) }}
                </span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    Status: {{ $post->status }}
                </span>
            </div>

            <div class="whitespace-pre-wrap text-sm text-[#1E1E1E] leading-relaxed">
                {{ $post->body }}
            </div>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-2">Media</div>
            @if ($post->layout === 'collage' && $post->collage_path)
                <img src="{{ \App\Support\StorefrontAssets::url($post->collage_path) }}" alt="" class="w-full rounded-lg border border-[#E7DFCF]">
            @elseif ($post->thumbnail_path)
                <img src="{{ \App\Support\StorefrontAssets::url($post->thumbnail_path) }}" alt="" class="w-full rounded-lg border border-[#E7DFCF]">
            @else
                <div class="text-sm text-[#8C8474]">No thumbnail.</div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-3">Products</div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                @foreach ($post->products as $product)
                    @php $thumb = $product->primaryImagePath(); @endphp
                    <a href="{{ route('product.show', $product) }}" wire:navigate
                        class="rounded-lg border border-[#E7DFCF] p-3 hover:bg-[#FAF6EF]/50 transition">
                        <div class="h-16 w-16 rounded bg-[#FAF6EF] flex items-center justify-center overflow-hidden">
                            @if ($thumb)
                                <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="h-full w-full object-cover">
                            @else
                                <span class="text-[#C9A227] text-xs">No img</span>
                            @endif
                        </div>
                        <div class="mt-2 text-sm font-medium line-clamp-1">{{ $product->name }}</div>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-3">Publishing attempts</div>

            @forelse ($post->publications as $pub)
                <div class="mb-3 p-3 border border-[#E7DFCF] rounded-lg bg-[#FAF6EF]/30">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium capitalize">{{ $pub->channel }}</div>
                        <div class="text-xs px-2 py-1 rounded-full bg-white border border-[#E7DFCF] text-[#6B6459]">
                            {{ $pub->status }}
                        </div>
                    </div>
                    @if ($pub->published_at)
                        <div class="text-xs text-[#8C8474] mt-1">At {{ $pub->published_at->diffForHumans() }}</div>
                    @endif

                    @if ($pub->external_url)
                        <a href="{{ $pub->external_url }}" target="_blank" rel="noreferrer"
                            class="text-xs mt-2 inline-flex font-medium text-[#C9A227] hover:underline">
                            View on Facebook/IG
                        </a>
                    @endif

                    @if ($pub->error)
                        <div class="text-xs text-rose-600 mt-2 line-clamp-3">{{ $pub->error }}</div>
                    @endif
                </div>
            @empty
                <div class="text-sm text-[#8C8474]">No publish attempts yet.</div>
            @endforelse
        </div>
    </div>
</div>

