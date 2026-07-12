<div
    @if ($active)
        wire:poll.1s="tickRebuild"
    @endif
>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Image Hashes</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Perceptual hashes power paste-to-match on Create Order. Missing hashes are filled from local files or the CDN.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-2 text-sm text-[#6B6459]">
                <input type="checkbox" wire:model="forceRehash" class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]" @disabled($active)>
                Re-hash existing
            </label>
            <button
                type="button"
                wire:click="startRebuild"
                wire:loading.attr="disabled"
                @disabled($active || ! $gdAvailable)
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span wire:loading.remove wire:target="startRebuild">{{ $active ? 'Rebuild in progress…' : 'Rebuild image hashes' }}</span>
                <span wire:loading wire:target="startRebuild">Starting…</span>
            </button>
        </div>
    </div>

    @unless ($gdAvailable)
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            PHP GD extension is required to hash images. Enable <code class="font-mono text-xs">extension=gd</code> on the server.
        </div>
    @endunless

    <div class="grid gap-4 lg:grid-cols-3 mb-8">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Coverage</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">
                {{ number_format($coverage['hashed']) }}
                <span class="text-base font-normal text-[#8C8474]">/ {{ number_format($coverage['total']) }}</span>
            </p>
            @if ($coverage['missing'] > 0)
                <p class="mt-3 text-sm text-amber-800">{{ number_format($coverage['missing']) }} image(s) still missing a hash.</p>
            @elseif ($coverage['total'] > 0)
                <p class="mt-3 text-sm text-emerald-700">All product images have a perceptual hash.</p>
            @else
                <p class="mt-3 text-sm text-[#6B6459]">No product images yet.</p>
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
                <p class="mt-3 text-sm text-[#6B6459]">Click rebuild to backfill missing hashes.</p>
            @endif
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Cron URL (optional)</p>
            <p class="mt-2 text-xs text-[#8C8474]">
                Hosting panel cron can hit this URL — no SSH needed. Add <span class="font-mono">&amp;force=1</span> to re-hash everything.
            </p>
            @if ($tokenConfigured)
                <p class="mt-2 text-xs text-emerald-700">PRODUCT_IMAGE_HASH_REBUILD_TOKEN is configured.</p>
            @else
                <p class="mt-2 text-xs text-amber-800">Set PRODUCT_IMAGE_HASH_REBUILD_TOKEN in .env to enable the cron URL.</p>
            @endif
            <p class="mt-2 text-[11px] text-[#8C8474] break-all font-mono">{{ $rebuildUrlHint }}</p>
        </div>
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
                            <td class="px-4 py-3">{{ $row->force ? 'force' : 'missing only' }}</td>
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
</div>
