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
</div>
