<div>
    <a href="{{ route('admin.materials') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Materials</a>
    <h1 class="mt-2 mb-6 font-serif text-3xl font-semibold">{{ $material?->name ?? 'Create Material' }}</h1>

    @if ($message)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="max-w-2xl space-y-4 rounded-xl border border-[#EFE7D6] bg-white p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium">Name</label>
                <input type="text" wire:model="name" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">SKU</label>
                <input type="text" wire:model="sku" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('sku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Unit</label>
                <input type="text" wire:model="unit" placeholder="pcs, m, kg…" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('unit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Unit cost (৳)</label>
                <input type="number" min="0" step="0.01" wire:model="unit_cost" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('unit_cost') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Stock quantity</label>
                <input type="number" min="0" step="0.001" wire:model="stock_quantity" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('stock_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium">Notes</label>
                <textarea wire:model="notes" rows="2" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm"></textarea>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $material ? 'Save Material' : 'Create Material' }}
            </button>
            @if ($material && $canDelete)
                <button type="button" wire:click="delete" wire:confirm="Delete this material?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @elseif ($material)
                <p class="text-xs text-[#8C8474]">Delete is disabled while products use this material.</p>
            @endif
        </div>
    </form>

    @if ($material)
        <div class="mt-6 max-w-2xl space-y-4 rounded-xl border border-[#EFE7D6] bg-white p-6">
            <h2 class="font-serif text-xl font-semibold">Receive stock (purchase)</h2>
            <p class="text-sm text-[#8C8474]">Adds to inventory and updates moving-average unit cost. Do not also book this as an Expense.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Quantity received</label>
                    <input type="number" min="0.001" step="0.001" wire:model="receive_quantity" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('receive_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Total paid (৳)</label>
                    <input type="number" min="0" step="0.01" wire:model="receive_total_cost" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('receive_total_cost') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <button type="button" wire:click="receiveStock"
                class="rounded-full border border-[#C9A227] px-6 py-2 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                Receive stock
            </button>
        </div>
    @endif
</div>
