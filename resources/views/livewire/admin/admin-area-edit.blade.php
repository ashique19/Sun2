<div>
    <a href="{{ route('admin.areas', array_filter(['city' => $cityId])) }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Areas</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $area?->name ?? 'Create Area' }}</h1>

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
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Slug</label>
                <input type="text" wire:model="slug" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                <p class="text-xs text-[#8C8474] mt-1">Stable identifier. Prefer Aliases below for spellings / Bangla names.</p>
                @error('slug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Aliases</label>
                <textarea wire:model="aliasesText" rows="3" placeholder="chatteswari&#10;Chatteswari Road&#10;চট্টেশ্বরী"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm"></textarea>
                <p class="text-xs text-[#8C8474] mt-1">
                    One per line (or comma-separated). Used by Create Order address auto-detect.
                    The app can also append aliases when you correct a missed area on an order.
                </p>
                @error('aliasesText') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">City</label>
                <select wire:model="cityId" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="">Select city</option>
                    @foreach ($cities as $cityOption)
                        <option value="{{ $cityOption->id }}">{{ $cityOption->name }}</option>
                    @endforeach
                </select>
                @error('cityId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Police station</label>
                <input type="text" wire:model="police_station" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('police_station') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Unit type</label>
                <input type="text" wire:model="unit_type" placeholder="e.g. thana, upazila"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('unit_type') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Delivery ≤5 items (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="delivery_charge_upto_5"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('delivery_charge_upto_5') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Delivery &gt;5 items (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="delivery_charge_over_5"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('delivery_charge_over_5') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Active
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $area ? 'Save Area' : 'Create Area' }}
            </button>

            @if ($area)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this area?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @endif
        </div>
    </form>
</div>
