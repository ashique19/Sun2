<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold text-[#1E1E1E]">Ads</h1>
            <p class="mt-1 max-w-2xl text-sm text-[#8C8474]">
                Turn storefront ad placements on or off without editing <code class="text-[12px]">.env</code>.
                Saved values live in the database and override config defaults.
            </p>
        </div>
        <a href="{{ route('ads.lab') }}" target="_blank" rel="noopener noreferrer"
            class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Open ads lab ↗
        </a>
    </div>

    @if ($statusMessage)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $statusMessage }}</p>
    @endif
    @if ($error)
        <p class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>
    @endif

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Storefront placements</p>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/50 px-3 py-3">
            <input type="checkbox" wire:model="productAfterDescription"
                class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
            <span>
                <span class="block text-sm font-semibold text-[#1E1E1E]">Product page banners</span>
                <span class="mt-0.5 block text-xs text-[#8C8474]">728×90 on desktop and 320×50 on mobile, after the product description.</span>
            </span>
        </label>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/50 px-3 py-3">
            <input type="checkbox" wire:model="productVideo"
                class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
            <span>
                <span class="block text-sm font-semibold text-[#1E1E1E]">Product page video ad</span>
                <span class="mt-0.5 block text-xs text-[#8C8474]">HilltopAds (or similar) video loader on product detail only.</span>
            </span>
        </label>

        <div class="rounded-xl border border-[#E7DFCF] px-3 py-3">
            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Video script URL</label>
            <input type="text" wire:model="productVideoSrc"
                placeholder="//quarrelsomebitter.com/…"
                class="w-full rounded-xl border border-[#E0D6C2] bg-white px-3 py-2 font-mono text-xs focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
            @error('productVideoSrc') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-[#8C8474]">Protocol-relative <code class="text-[11px]">//host/…</code> URLs are fine.</p>
        </div>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/50 px-3 py-3">
            <input type="checkbox" wire:model="popunder"
                class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
            <span>
                <span class="block text-sm font-semibold text-[#1E1E1E]">Popunder (first click)</span>
                <span class="mt-0.5 block text-xs text-[#8C8474]">Excluded on cart, checkout, auth, and admin routes.</span>
            </span>
        </label>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/50 px-3 py-3">
            <input type="checkbox" wire:model="exitInterstitial"
                class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
            <span>
                <span class="block text-sm font-semibold text-[#1E1E1E]">Exit interstitial</span>
                <span class="mt-0.5 block text-xs text-[#8C8474]">Back button / desktop exit-intent countdown modal with smartlink.</span>
            </span>
        </label>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/50 px-3 py-3">
            <input type="checkbox" wire:model="labEnabled"
                class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
            <span>
                <span class="block text-sm font-semibold text-[#1E1E1E]">Ads lab page</span>
                <span class="mt-0.5 block text-xs text-[#8C8474]">Public <code class="text-[11px]">/ads-lab</code> preview (noindex). Unit codes still come from DB settings.</span>
            </span>
        </label>

        <div class="flex flex-wrap gap-2 border-t border-[#EFE7D6] pt-4">
            <button type="submit"
                class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b89220]">
                Save
            </button>
            <button type="button" wire:click="resetDefaults"
                wire:confirm="Clear saved ads settings and restore config / .env defaults?"
                class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#8C8474] hover:text-[#1E1E1E]">
                Reset to defaults
            </button>
        </div>
    </form>
</div>
