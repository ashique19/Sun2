<div class="space-y-6" data-inbox-automations>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold text-[#1E1E1E]">Automations</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Controlled auto-replies when a customer asks for a price (messages tagged against “কোন প্রডাক্ট?”) in the current session.
            </p>
        </div>
        <a href="{{ route('admin.inbox') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Back to Inbox
        </a>
    </div>

    @if ($statusMessage)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $statusMessage }}</p>
    @endif

    <div class="space-y-3 rounded-2xl border border-[#EFE7D6] bg-white p-4 sm:p-5">
        <div class="flex flex-col gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4 sm:flex-row sm:items-start sm:justify-between" data-inbox-automation="photo_request">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Ask for a product photo</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they ask for a price and have not sent a product image.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $photoRequestBody }}</p>
            </div>
            <button type="button"
                wire:click="togglePhotoRequest"
                role="switch"
                aria-checked="{{ $photoRequest ? 'true' : 'false' }}"
                aria-label="Ask for a product photo"
                class="relative inline-flex h-7 w-12 shrink-0 rounded-full border transition {{ $photoRequest ? 'border-[#C9A227] bg-[#C9A227]' : 'border-[#E0D6C2] bg-[#E7DFCF]' }}">
                <span class="mt-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $photoRequest ? 'ml-6' : 'ml-0.5' }}"></span>
            </button>
        </div>

        <div class="flex flex-col gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4 sm:flex-row sm:items-start sm:justify-between" data-inbox-automation="priced_image">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Send priced image</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they asked for a price and a product photo matches {{ number_format($matchMinPercent, 0) }}% or higher, send P.img automatically.</p>
            </div>
            <button type="button"
                wire:click="togglePricedImage"
                role="switch"
                aria-checked="{{ $pricedImage ? 'true' : 'false' }}"
                aria-label="Send priced image"
                class="relative inline-flex h-7 w-12 shrink-0 rounded-full border transition {{ $pricedImage ? 'border-[#C9A227] bg-[#C9A227]' : 'border-[#E0D6C2] bg-[#E7DFCF]' }}">
                <span class="mt-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $pricedImage ? 'ml-6' : 'ml-0.5' }}"></span>
            </button>
        </div>

        <div class="flex flex-col gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4 sm:flex-row sm:items-start sm:justify-between" data-inbox-automation="low_match_wait">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Ask for a moment on a weak match</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they asked for a price and the photo match is below {{ number_format($matchMinPercent, 0) }}%.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $lowMatchWaitBody }}</p>
            </div>
            <button type="button"
                wire:click="toggleLowMatchWait"
                role="switch"
                aria-checked="{{ $lowMatchWait ? 'true' : 'false' }}"
                aria-label="Ask for a moment on a weak match"
                class="relative inline-flex h-7 w-12 shrink-0 rounded-full border transition {{ $lowMatchWait ? 'border-[#C9A227] bg-[#C9A227]' : 'border-[#E0D6C2] bg-[#E7DFCF]' }}">
                <span class="mt-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $lowMatchWait ? 'ml-6' : 'ml-0.5' }}"></span>
            </button>
        </div>
    </div>
</div>
