<div>
    <a href="{{ route('admin.couriers') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Couriers</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $courier?->name ?? 'Create Courier' }}</h1>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-2xl">
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Name</label>
                <input type="text" wire:model.live="name" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">API slug</label>
                <select wire:model="slug" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="">— Manual only (no API) —</option>
                    @foreach ($apiSlugs as $apiSlug)
                        <option value="{{ $apiSlug }}">{{ $apiSlug }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-[#8C8474] mt-1">Use <code class="text-[11px]">steadfast</code> for Steadfast API dispatch. Must match configured courier credentials.</p>
                @error('slug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Charge (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="charge" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('charge') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Outside Dhaka charge (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="osd_charge" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('osd_charge') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Customer charge (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="customer_charge" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('customer_charge') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Customer OSD charge (&#2547;)</label>
                <input type="number" min="0" step="1" wire:model="customer_osd_charge" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('customer_osd_charge') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">COD percentage</label>
                <input type="number" min="0" max="100" step="0.01" wire:model="cod_percentage" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('cod_percentage') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Balance (&#2547;)</label>
                <input type="number" step="1" wire:model="balance" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                <p class="text-xs text-[#8C8474] mt-1">Positive = courier owes you (COD held). Update when reconciling payments.</p>
                @error('balance') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 flex flex-wrap gap-6">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                    Active
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_default" class="rounded border-[#E0D6C2] text-[#C9A227]">
                    Default courier
                </label>
            </div>
            @error('is_active') <p class="text-xs text-rose-600 sm:col-span-2">{{ $message }}</p> @enderror
            @error('is_default') <p class="text-xs text-rose-600 sm:col-span-2">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $courier ? 'Save Courier' : 'Create Courier' }}
            </button>
            @if ($courier && $canDelete)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this courier?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @elseif ($courier)
                <p class="text-xs text-[#8C8474]">Delete is disabled for the default courier or when orders still reference it.</p>
            @endif
        </div>
    </form>
</div>
