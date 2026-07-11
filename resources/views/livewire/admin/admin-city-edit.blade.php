<div>
    <a href="{{ route('admin.cities') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Cities</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $city?->name ?? 'Create City' }}</h1>

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
                <label class="block text-sm font-medium mb-1">Division</label>
                <input type="text" wire:model="division" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('division') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Active
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_dhaka" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Dhaka pricing
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $city ? 'Save City' : 'Create City' }}
            </button>

            @if ($city)
                <a href="{{ route('admin.areas', ['city' => $city->id]) }}" wire:navigate
                    class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                    Areas ({{ $areasCount }})
                </a>
                <a href="{{ route('admin.areas.create', ['city' => $city->id]) }}" wire:navigate
                    class="rounded-full border border-[#C9A227] px-6 py-2.5 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                    Add area
                </a>
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this city?{{ $areasCount > 0 ? ' This will also delete '.$areasCount.' areas.' : '' }}"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @endif
        </div>
    </form>
</div>
