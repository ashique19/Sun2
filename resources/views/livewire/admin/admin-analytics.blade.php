<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Analytics</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Choose a report. Each opens its own page so the hub stays easy to scan.
            </p>
        </div>
        <a href="{{ route('admin.expenses') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
            Expenses
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as $tile)
            <a href="{{ route($tile['route']) }}" wire:navigate
                class="group rounded-xl border border-[#EFE7D6] bg-white p-5 transition hover:border-[#C9A227] hover:bg-[#FAF6EF]/50">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-base font-semibold text-[#1E1E1E]">{{ $tile['title'] }}</h2>
                    <span class="text-xs font-medium text-[#C9A227] opacity-0 transition group-hover:opacity-100">Open →</span>
                </div>
                <p class="mt-2 text-sm text-[#6B6459]">{{ $tile['blurb'] }}</p>
                <p class="mt-4 text-xs tabular-nums text-[#8C8474]">{{ $tile['stat'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-8 rounded-xl border border-[#EFE7D6] bg-white p-5">
        <h2 class="text-sm font-semibold text-[#1E1E1E]">Storefront tracking</h2>
        <p class="mt-1 text-xs text-[#8C8474]">
            IDs currently loaded from config / <code class="text-[11px]">.env</code> on this environment.
        </p>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-[#F0EBE0] bg-[#FAF6EF]/50 px-4 py-3">
                <dt class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Google Analytics</dt>
                <dd class="mt-1 flex items-start gap-2">
                    @if (filled($googleAnalyticsId))
                        <span class="min-w-0 flex-1 break-all font-mono text-sm tabular-nums text-[#1E1E1E]">{{ $googleAnalyticsId }}</span>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            data-copy-text="{{ $googleAnalyticsId }}"
                            x-on:click="
                                window.sunCopyText($el.dataset.copyText).then((ok) => {
                                    if (! ok) return;
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                })
                            "
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]"
                            title="Copy Google Analytics ID"
                            aria-label="Copy Google Analytics ID"
                        >
                            <svg x-show="! copied" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.75"/>
                                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>
                            </svg>
                            <svg x-cloak x-show="copied" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 text-emerald-600" aria-hidden="true">
                                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
                            </svg>
                        </button>
                    @else
                        <span class="font-sans text-sm text-[#8C8474]">Not configured</span>
                    @endif
                </dd>
            </div>
            <div class="rounded-lg border border-[#F0EBE0] bg-[#FAF6EF]/50 px-4 py-3">
                <dt class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Meta Pixel</dt>
                <dd class="mt-1 flex items-start gap-2">
                    @if (filled($metaPixelId))
                        <span class="min-w-0 flex-1 break-all font-mono text-sm tabular-nums text-[#1E1E1E]">{{ $metaPixelId }}</span>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            data-copy-text="{{ $metaPixelId }}"
                            x-on:click="
                                window.sunCopyText($el.dataset.copyText).then((ok) => {
                                    if (! ok) return;
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                })
                            "
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]"
                            title="Copy Meta Pixel ID"
                            aria-label="Copy Meta Pixel ID"
                        >
                            <svg x-show="! copied" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <rect x="9" y="9" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.75"/>
                                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>
                            </svg>
                            <svg x-cloak x-show="copied" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 text-emerald-600" aria-hidden="true">
                                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
                            </svg>
                        </button>
                    @else
                        <span class="font-sans text-sm text-[#8C8474]">Not configured</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>
</div>
