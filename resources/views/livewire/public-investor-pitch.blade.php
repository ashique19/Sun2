<div>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        @if ($revoked || $expired)
            <div class="mx-auto max-w-md rounded-2xl border border-[#EFE7D6] bg-white p-8 text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#C9A227]">Sundoritoma</p>
                <h1 class="mt-2 font-serif text-2xl font-semibold text-[#1E1E1E]">
                    {{ $revoked ? 'This share link was revoked' : 'This share link has expired' }}
                </h1>
                <p class="mt-2 text-sm text-[#6B6459]">
                    Ask your Sundoritoma contact for a fresh link if you still need access.
                </p>
            </div>
        @elseif (! $unlocked)
            <div class="mx-auto max-w-md space-y-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#C9A227]">Sundoritoma</p>
                    <h1 class="mt-2 font-serif text-3xl font-semibold text-[#1E1E1E]">Investor pitch</h1>
                    <p class="mt-2 text-sm text-[#6B6459]">
                        Enter the share password to view the live calendar-year deck.
                    </p>
                    @if ($share?->expires_at)
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Valid until {{ $share->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} (Asia/Dhaka)
                        </p>
                    @endif
                </div>

                <form wire:submit="unlock" class="space-y-4 rounded-2xl border border-[#EFE7D6] bg-white p-6">
                    <div>
                        <label for="share-password" class="block text-sm font-medium text-[#1E1E1E]">Password</label>
                        <input
                            id="share-password"
                            type="password"
                            wire:model="password"
                            autocomplete="current-password"
                            class="mt-1 w-full rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm text-[#1E1E1E] shadow-sm focus:border-[#C9A227] focus:outline-none focus:ring-2 focus:ring-[#C9A227]/20"
                        >
                        @error('password')
                            <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-[#1E1E1E] px-4 py-2.5 text-sm font-semibold text-white hover:bg-black"
                    >
                        Unlock deck
                    </button>
                </form>
            </div>
        @else
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#C9A227]">Sundoritoma</p>
                    <h1 class="mt-1 font-serif text-3xl font-semibold text-[#1E1E1E]">Investor pitch deck</h1>
                    <p class="mt-1 text-sm text-[#6B6459]">
                        Yearly report from orders · compared with prior year · as of {{ $deck['as_of'] }}
                    </p>
                    @if ($share?->expires_at)
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Link valid until {{ $share->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}
                        </p>
                    @endif
                </div>
                <button
                    type="button"
                    wire:click="lock"
                    class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]"
                >
                    Lock
                </button>
            </div>

            @include('livewire.partials.investor-pitch-deck-body', [
                'deck' => $deck,
                'years' => $years,
                'year' => $year,
                'showAdminLinks' => false,
            ])
        @endif
    </div>
</div>
