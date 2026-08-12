<div class="investor-pitch">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Investor pitch</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Investor pitch deck</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Yearly report from orders · compared with prior year · as of {{ $deck['as_of'] }}
            </p>
        </div>
        @if ($shareUrl)
            <button
                type="button"
                x-data="{ copied: false }"
                x-on:click="
                    navigator.clipboard.writeText(@js($shareUrl)).then(() => {
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    })
                "
                class="rounded-full border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
            >
                <span x-text="copied ? 'Link copied' : 'Copy share link'"></span>
            </button>
        @endif
    </div>

    <x-admin.analytics-subnav active="investor" />

    @if ($shareUrl)
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
            <p class="font-medium">Password-protected share link is live</p>
            <p class="mt-1 break-all font-mono text-xs text-emerald-900/90">{{ $shareUrl }}</p>
            <p class="mt-2 text-xs text-emerald-900/80">
                Recipients need the share password from <code class="rounded bg-white/70 px-1">INVESTOR_PITCH_SHARE_PASSWORD</code>.
                They will not see admin navigation.
            </p>
        </div>
    @else
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-medium">Share link not configured</p>
            <p class="mt-1 text-xs text-amber-900/80">
                Set <code class="rounded bg-white/70 px-1">INVESTOR_PITCH_SHARE_TOKEN</code> and
                <code class="rounded bg-white/70 px-1">INVESTOR_PITCH_SHARE_PASSWORD</code> in the environment to publish a password-gated public URL.
            </p>
        </div>
    @endif

    @include('livewire.partials.investor-pitch-deck-body', [
        'deck' => $deck,
        'years' => $years,
        'year' => $year,
        'showAdminLinks' => true,
    ])
</div>
