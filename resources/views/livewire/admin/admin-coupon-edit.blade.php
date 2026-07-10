<div>
    <a href="{{ route('admin.coupons') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Coupons</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $coupon?->code ?? 'Create Coupon' }}</h1>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-2xl">
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Code</label>
                <input type="text" wire:model="code" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm uppercase tracking-wide">
                @error('code') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Type</label>
                <select wire:model="type" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="fixed">Fixed ৳</option>
                    <option value="percent">Percent %</option>
                </select>
                @error('type') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Value</label>
                <input type="number" min="0" step="1" wire:model="value" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('value') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Minimum order (৳)</label>
                <input type="number" min="0" step="1" wire:model="min_order" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('min_order') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Starts at</label>
                <input type="datetime-local" wire:model="starts_at" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('starts_at') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Ends at</label>
                <input type="datetime-local" wire:model="ends_at" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('ends_at') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Usage limit</label>
                <input type="number" min="1" wire:model="usage_limit" placeholder="Unlimited" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                <p class="text-xs text-[#8C8474] mt-1">Leave empty for unlimited uses.</p>
                @error('usage_limit') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            @if ($coupon)
                <div>
                    <label class="block text-sm font-medium mb-1">Used</label>
                    <p class="rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-4 py-2 text-sm tabular-nums">{{ number_format($coupon->used_count) }}</p>
                </div>
            @endif
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                Active
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $coupon ? 'Save Coupon' : 'Create Coupon' }}
            </button>
            @if ($coupon)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this coupon?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @endif
        </div>
    </form>
</div>
