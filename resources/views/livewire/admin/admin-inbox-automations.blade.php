<div class="space-y-6" data-inbox-automations>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold text-[#1E1E1E]">Automations</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Controlled auto-replies in the current session (past hour): price asks tagged against “কোন প্রডাক্ট?”, greetings tagged against “Hi”, “{{ $colorGuaranteeLabel }}” only for “{{ $colorGuaranteeCategory }}”, and “{{ $deliveryChargeLabel }}”.
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
        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="photo_request">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Ask for a product photo</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they ask for a price and have not sent a product image.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $photoRequestBody }}</p>
            </div>
            <x-admin.switch :on="$photoRequest" wire:click="togglePhotoRequest" aria-label="Ask for a product photo" />
        </div>

        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="priced_image">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Send priced image</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they asked for a price and a product photo matches {{ number_format($matchMinPercent, 0) }}% or higher, send P.img automatically.</p>
            </div>
            <x-admin.switch :on="$pricedImage" wire:click="togglePricedImage" aria-label="Send priced image" />
        </div>

        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="low_match_wait">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Ask for a moment on a weak match</p>
                <p class="mt-1 text-sm text-[#6B6459]">When they asked for a price and the photo match is below {{ number_format($matchMinPercent, 0) }}%.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $lowMatchWaitBody }}</p>
            </div>
            <x-admin.switch :on="$lowMatchWait" wire:click="toggleLowMatchWait" aria-label="Ask for a moment on a weak match" />
        </div>

        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="hi_reply">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Reply Hi apu</p>
                <p class="mt-1 text-sm text-[#6B6459]">When a message is tagged against the “Hi” saved reply, or they just say hi.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $hiReplyBody }}</p>
            </div>
            <x-admin.switch :on="$hiReply" wire:click="toggleHiReply" aria-label="Reply Hi apu" />
        </div>

        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="color_guarantee">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Match {{ $colorGuaranteeLabel }}</p>
                <p class="mt-1 text-sm text-[#6B6459]">Send the “{{ $colorGuaranteeLabel }}” saved reply only when a product in the current session is in “{{ $colorGuaranteeCategory }}”.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $colorGuaranteeBody }}</p>
            </div>
            <x-admin.switch :on="$colorGuarantee" wire:click="toggleColorGuarantee" aria-label="Match color guarantee" />
        </div>

        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-4" data-inbox-automation="delivery_charge">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-[#1E1E1E]">Match {{ $deliveryChargeLabel }}</p>
                <p class="mt-1 text-sm text-[#6B6459]">Send the “{{ $deliveryChargeLabel }}” saved reply when they ask delivery time or charge. Edit that chip to change the copy.</p>
                <p class="mt-2 rounded-lg bg-white px-3 py-2 text-sm text-[#1E1E1E]">{{ $deliveryChargeBody }}</p>
            </div>
            <x-admin.switch :on="$deliveryCharge" wire:click="toggleDeliveryCharge" aria-label="Match delivery charge" />
        </div>
    </div>
</div>
