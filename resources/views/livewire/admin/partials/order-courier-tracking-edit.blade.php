<form wire:submit="saveCourierTracking" class="space-y-3 border-t border-[#E7DFCF] pt-4 text-sm">
    <p class="text-xs font-medium uppercase tracking-wide text-[#8C8474]">Fix tracking</p>
    <div>
        <label class="mb-1 block text-[#6B6459]">Tracking code</label>
        <input type="text" wire:model="editTracker" autocomplete="off"
            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        @error('editTracker') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="mb-1 block text-[#6B6459]">Parcel ID</label>
        <input type="text" wire:model="editConsignmentId" autocomplete="off" placeholder="Steadfast consignment id"
            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        @error('editConsignmentId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>
    <button type="submit"
        wire:loading.attr="disabled"
        wire:target="saveCourierTracking"
        class="w-full rounded-full border border-[#C9A227] px-4 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF] transition disabled:opacity-60">
        <span wire:loading.remove wire:target="saveCourierTracking">Save tracking</span>
        <span wire:loading wire:target="saveCourierTracking">Saving…</span>
    </button>
</form>
