<div>
    <a href="{{ route('admin.hero-slides') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Hero Slides</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $slide ? 'Edit Hero Slide' : 'Create Hero Slide' }}</h1>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-2xl">
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Title</label>
                <input type="text" wire:model="titleText" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('titleText') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Subtitle</label>
                <input type="text" wire:model="subtitle" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('subtitle') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Button label</label>
                <input type="text" wire:model="link_label" placeholder="Shop Collection" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Button link</label>
                <input type="text" wire:model="link_url" placeholder="/search or #collection" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Display order</label>
                <input type="number" min="0" wire:model="display_order" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm self-end pb-2">
                <input type="checkbox" wire:model="is_published" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Published
            </label>

            <div class="sm:col-span-2 space-y-3">
                <label class="block text-sm font-medium">Image</label>
                <p class="text-xs text-[#8C8474]">Resized to {{ \App\Services\Admin\HeroSlideImageService::MAX_EDGE }}px on the longest side. Wide landscape works best.</p>
                @if ($imagePreviewUrl)
                    <img src="{{ $imagePreviewUrl }}" alt="Hero preview"
                        class="h-40 w-full max-w-xl rounded-lg object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                @endif
                <input type="file" wire:model="imageUpload" accept="image/jpeg,image/png,image/webp,gif"
                    class="block w-full text-sm text-[#6B6459] file:mr-3 file:rounded-lg file:border-0 file:bg-[#FAF6EF] file:px-3 file:py-2 file:text-sm file:font-medium file:text-[#1E1E1E] hover:file:bg-[#F1EADB]">
                <div wire:loading wire:target="imageUpload" class="text-xs text-[#8C8474]">Uploading…</div>
                @error('imageUpload') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $slide ? 'Save Slide' : 'Create Slide' }}
            </button>
            @if ($slide)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this hero slide?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @endif
        </div>
    </form>
</div>
