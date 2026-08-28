<div wire:poll.1s.keep-alive="tickRebuild">
    @if ($statusMessage)
        <x-admin.toast
            :message="$statusMessage"
            type="success"
            dismiss-method="dismissStatusMessage"
            :ms="6000"
            bottom="bottom-4"
        />
    @endif

    @if ($errorMessage)
        <x-admin.toast
            :message="$errorMessage"
            type="error"
            dismiss-method="dismissErrorMessage"
            :ms="10000"
            dismissable
            bottom="bottom-4"
        />
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Image Hashes</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Perceptual hashes, DCT hashes, crop variants, and embeddings power paste-to-match on Create Order and inbox screenshot product recognition.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="openRebuildModal"
                wire:loading.attr="disabled"
                @disabled($active || ! $gdAvailable)
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span wire:loading.remove wire:target="openRebuildModal,confirmRebuild">{{ $active ? 'Rebuild in progress…' : 'Rebuild image hashes' }}</span>
                <span wire:loading wire:target="openRebuildModal,confirmRebuild">Starting…</span>
            </button>
        </div>
    </div>

    @unless ($gdAvailable)
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            PHP GD extension is required to hash images. Enable <code class="font-mono text-xs">extension=gd</code> on the server.
        </div>
    @endunless

    @if ($coverage['needs_screenshot_backfill'] > 0)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Screenshot matching needs a catalog backfill</p>
            <p class="mt-1">
                {{ number_format($coverage['needs_screenshot_backfill']) }} product image(s) are missing DCT hashes, crop variants, and/or embeddings.
                Open <strong>Rebuild image hashes</strong> and run a backfill — no SSH or artisan command required.
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3 mb-8">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Full index</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">
                {{ number_format($coverage['fully_indexed']) }}
                <span class="text-base font-normal text-[#8C8474]">/ {{ number_format($coverage['total']) }}</span>
            </p>
            @if ($coverage['needs_screenshot_backfill'] > 0)
                <p class="mt-3 text-sm text-amber-800">
                    {{ number_format($coverage['needs_screenshot_backfill']) }} image(s) need DCT / crop variants / embeddings for inbox screenshots.
                </p>
            @elseif ($coverage['total'] > 0)
                <p class="mt-3 text-sm text-emerald-700">Catalog fully indexed for screenshot matching.</p>
            @else
                <p class="mt-3 text-sm text-[#6B6459]">No product images yet.</p>
            @endif
            @if ($coverage['missing_dct'] > 0 || $coverage['missing_embedding'] > 0)
                <p class="mt-2 text-xs text-[#8C8474]">
                    Missing DCT: {{ number_format($coverage['missing_dct']) }}
                    · Missing embeddings: {{ number_format($coverage['missing_embedding']) }}
                </p>
            @endif
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Legacy dHash only</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">
                {{ number_format($coverage['hashed']) }}
                <span class="text-base font-normal text-[#8C8474]">/ {{ number_format($coverage['total']) }}</span>
            </p>
            @if ($coverage['missing'] > 0)
                <p class="mt-3 text-sm text-amber-800">{{ number_format($coverage['missing']) }} image(s) still missing any hash.</p>
            @elseif ($coverage['total'] > 0)
                <p class="mt-3 text-sm text-[#6B6459]">All images have at least a basic perceptual hash.</p>
            @endif
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Status</p>
            @php
                $status = $active?->status ?? $latest?->status ?? 'never';
                $statusClass = match ($status) {
                    'completed' => 'text-emerald-700 bg-emerald-50',
                    'running', 'pending' => 'text-amber-800 bg-amber-50',
                    'failed' => 'text-rose-700 bg-rose-50',
                    default => 'text-[#6B6459] bg-[#FAF6EF]',
                };
                $run = $active ?? $latest;
            @endphp
            <p class="mt-2">
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                    {{ $status === 'never' ? 'Not run yet' : ucfirst($status) }}
                </span>
            </p>
            @if ($run)
                <p class="mt-3 text-2xl font-semibold tabular-nums">{{ $run->progressPercent() }}%</p>
                <div class="mt-3 h-2 rounded-full bg-[#FAF6EF] overflow-hidden">
                    <div class="h-full rounded-full bg-[#C9A227] transition-all duration-300" style="width: {{ $run->progressPercent() }}%"></div>
                </div>
                <p class="mt-2 text-sm text-[#6B6459]">
                    {{ number_format($run->progress_current) }} / {{ number_format($run->progress_total) }} images
                    · ok {{ number_format($run->hashed_ok) }}
                    · failed {{ number_format($run->failed) }}
                </p>
                <p class="mt-1 text-sm text-[#8C8474]">{{ $run->message }}</p>
            @else
                <p class="mt-3 text-sm text-[#6B6459]">Click rebuild to backfill missing hashes in the browser.</p>
            @endif
        </div>
    </div>

    <div class="mb-8 rounded-xl border border-[#EFE7D6] bg-white p-5">
        <p class="text-xs uppercase tracking-wide text-[#8C8474]">Cron URL (optional)</p>
        <p class="mt-2 text-sm text-[#6B6459]">
            Hosting panel cron can hit this URL when the admin page is closed. Use <span class="font-mono text-xs">&amp;force=1</span> only when you need to re-hash every image.
        </p>
        @if ($tokenConfigured)
            <p class="mt-2 text-xs text-emerald-700">PRODUCT_IMAGE_HASH_REBUILD_TOKEN is configured.</p>
        @else
            <p class="mt-2 text-xs text-amber-800">Set PRODUCT_IMAGE_HASH_REBUILD_TOKEN in .env to enable the cron URL.</p>
        @endif
        <p class="mt-2 text-[11px] text-[#8C8474] break-all font-mono">{{ $rebuildUrlHint }}</p>
    </div>

    @if ($latest?->error)
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            Last error: {{ $latest->error }}
        </div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="px-4 py-3 border-b border-[#E7DFCF] bg-[#FAF6EF]">
            <h2 class="font-semibold text-sm">Recent rebuilds</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Trigger</th>
                        <th class="px-4 py-3 font-medium">Mode</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Progress</th>
                        <th class="px-4 py-3 font-medium">Ok / Failed</th>
                        <th class="px-4 py-3 font-medium">By</th>
                        <th class="px-4 py-3 font-medium">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($recentRuns as $row)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="image-hash-run-{{ $row->id }}">
                            <td class="px-4 py-3 whitespace-nowrap text-[#6B6459]">
                                {{ ($row->started_at ?? $row->created_at)?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3">{{ $row->trigger }}</td>
                            <td class="px-4 py-3">{{ $row->force ? 're-hash all' : 'backfill missing' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $row->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : '' }}
                                    {{ in_array($row->status, ['running', 'pending'], true) ? 'bg-amber-50 text-amber-800' : '' }}
                                    {{ $row->status === 'failed' ? 'bg-rose-50 text-rose-700' : '' }}">
                                    {{ $row->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ $row->progressPercent() }}%</td>
                            <td class="px-4 py-3 tabular-nums">{{ number_format($row->hashed_ok) }} / {{ number_format($row->failed) }}</td>
                            <td class="px-4 py-3">{{ $row->triggeredBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-[#6B6459] max-w-xs truncate" title="{{ $row->error ?: $row->message }}">
                                {{ $row->error ?: $row->message }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-[#8C8474]">No rebuild history yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @teleport('body')
        @if ($rebuildModalOpen)
            <div
                class="fixed inset-0 z-[80] flex items-end justify-center overflow-y-auto overscroll-contain bg-black/50 p-0 sm:items-center sm:p-4"
                wire:click.self="closeRebuildModal"
                wire:key="image-hash-rebuild-modal"
                role="dialog"
                aria-modal="true"
                aria-label="Rebuild product image hashes"
            >
                <div class="flex max-h-[min(90dvh,36rem)] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" wire:click.stop>
                    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-4">
                        <div>
                            <h2 class="font-semibold text-lg">Rebuild image hashes</h2>
                            <p class="mt-1 text-sm text-[#8C8474]">
                                Runs in the browser in small batches. Keep this tab open until progress reaches 100%.
                            </p>
                        </div>
                        <button type="button" wire:click="closeRebuildModal"
                            class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                            Close
                        </button>
                    </div>

                    <div class="max-h-[min(24rem,calc(90dvh-11rem))] overflow-y-auto px-4 py-4 space-y-4">
                        <div class="rounded-xl border border-[#E7DFCF] bg-[#FAF6EF] px-4 py-3 text-sm text-[#6B6459]">
                            <p class="font-semibold text-[#1E1E1E]">Backfill missing (recommended)</p>
                            <p class="mt-1">
                                Adds crop variants, DCT hashes, and embeddings for images that only have legacy dHash data.
                                Use this for inbox screenshot product matching.
                            </p>
                            @if ($coverage['needs_screenshot_backfill'] > 0)
                                <p class="mt-2 text-amber-800 font-medium">
                                    {{ number_format($coverage['needs_screenshot_backfill']) }} image(s) queued for backfill.
                                </p>
                            @else
                                <p class="mt-2 text-emerald-700">Nothing missing — catalog already fully indexed.</p>
                            @endif
                        </div>

                        <label class="flex items-start gap-3 rounded-xl border border-[#E7DFCF] px-4 py-3 text-sm text-[#6B6459]">
                            <input type="checkbox" wire:model="forceRehash" class="mt-0.5 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                            <span>
                                <span class="font-semibold text-[#1E1E1E]">Re-hash all images</span>
                                <span class="mt-1 block">
                                    Re-process every product photo even when hashes exist. Slower — use after bulk image replacements.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                        <button type="button" wire:click="closeRebuildModal"
                            class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                            Cancel
                        </button>
                        <button type="button"
                            wire:click="confirmRebuild"
                            wire:loading.attr="disabled"
                            wire:target="confirmRebuild"
                            @disabled(! $forceRehash && $coverage['needs_screenshot_backfill'] === 0)
                            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove wire:target="confirmRebuild">
                                {{ $forceRehash ? 'Re-hash all' : 'Backfill missing' }}
                            </span>
                            <span wire:loading wire:target="confirmRebuild">Starting…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endteleport
</div>
