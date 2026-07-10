<div>
    <a href="{{ route('admin.categories') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Categories</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $category?->name ?? 'Create Category' }}</h1>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-2xl">
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Name</label>
                <input type="text" wire:model.live="name" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Slug</label>
                <input type="text" wire:model="slug" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('slug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Display order</label>
                <input type="number" wire:model="display_order" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Headline</label>
                <input type="text" wire:model="headline" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Summary</label>
                <textarea wire:model="summary" rows="2" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm"></textarea>
            </div>

            <div class="sm:col-span-2 space-y-3">
                <label class="block text-sm font-medium">Thumbnail</label>
                <p class="text-xs text-[#8C8474]">Uploaded images are resized to {{ \App\Services\Admin\CategoryImageService::MAX_EDGE }}px on the longest side.</p>
                @if ($thumbPreviewUrl)
                    <div class="flex items-start gap-4">
                        <img src="{{ $thumbPreviewUrl }}" alt="Category thumbnail"
                            class="h-28 w-28 rounded-lg object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                        <button type="button" wire:click="clearThumbnail"
                            class="text-xs text-rose-600 hover:underline mt-1">Remove image</button>
                    </div>
                @endif
                <input type="file" wire:model="thumbUpload" accept="image/jpeg,image/png,image/webp,image/gif"
                    class="block w-full text-sm text-[#6B6459] file:mr-3 file:rounded-lg file:border-0 file:bg-[#FAF6EF] file:px-3 file:py-2 file:text-sm file:font-medium file:text-[#1E1E1E] hover:file:bg-[#F1EADB]">
                <div wire:loading wire:target="thumbUpload" class="text-xs text-[#8C8474]">Uploading…</div>
                @error('thumbUpload') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Active on storefront
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_homepage" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Show on homepage
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $category ? 'Save Category' : 'Create Category' }}
            </button>

            @if ($category && $canDelete)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this category? This cannot be undone."
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @elseif ($category)
                <p class="text-xs text-[#8C8474]">Delete is disabled while this category has products.</p>
            @endif
        </div>
    </form>
</div>
