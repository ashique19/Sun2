<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Edit Social Post #{{ $post->id }}</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Update copy and homepage visibility. Product images stay as saved at compose time.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.social-posts.show', $post) }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                View / re-post
            </a>
            <a href="{{ route('admin.social-posts') }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                All posts
            </a>
        </div>
    </div>

    <form wire:submit="save" class="space-y-4 max-w-3xl">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <label class="block text-sm font-medium mb-1">Post text</label>
            <textarea
                wire:model="body"
                rows="6"
                class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                placeholder="Post copy…"></textarea>
            @error('body')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Status</label>
                <select wire:model="status"
                    class="w-full max-w-xs rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                    <option value="failed">Failed</option>
                </select>
                <p class="mt-1 text-xs text-[#8C8474]">Only published posts can be opened on the storefront.</p>
                @error('status')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="showOnHomepage" class="mt-1 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                <span>
                    <span class="block text-sm font-medium">Show on homepage</span>
                    <span class="block text-xs text-[#8C8474]">Include this post in the Recent posts section when status is published.</span>
                </span>
            </label>
        </div>

        @if ($post->products->isNotEmpty())
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                <div class="font-medium text-sm mb-2">Linked products (read-only)</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($post->products as $product)
                        <span class="inline-flex rounded-full border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-1 text-xs text-[#6B6459]">
                            {{ $product->name }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <button type="submit"
                class="rounded-full bg-[#C9A227] px-6 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                Save changes
            </button>
            <a href="{{ route('admin.social-posts') }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] px-6 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                Cancel
            </a>
        </div>
    </form>
</div>
