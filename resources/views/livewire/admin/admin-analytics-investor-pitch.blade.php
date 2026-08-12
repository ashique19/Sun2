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
    </div>

    <x-admin.analytics-subnav active="investor" />

    <section class="mb-5 rounded-2xl border border-[#EFE7D6] bg-white p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-serif text-lg font-semibold text-[#1E1E1E]">Share with an investor</h2>
                <p class="mt-1 text-sm text-[#8C8474]">
                    Create a password-gated link. Each investor can get a different password and expiry.
                </p>
            </div>
        </div>

        @if ($sharesUnavailable ?? false)
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                <p class="font-medium">Share links are unavailable</p>
                <p class="mt-1 text-xs text-rose-900/80">
                    Could not prepare the <code class="rounded bg-white/70 px-1">investor_pitch_shares</code> table.
                    Check database permissions, then reload.
                </p>
            </div>
        @endif

        <form wire:submit="createShare" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="share-label" class="block text-xs font-medium text-[#6B6459]">Recipient label (optional)</label>
                <input
                    id="share-label"
                    type="text"
                    wire:model="shareLabel"
                    placeholder="Acme Ventures"
                    class="mt-1 w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm text-[#1E1E1E] focus:border-[#C9A227] focus:outline-none focus:ring-2 focus:ring-[#C9A227]/20"
                >
                @error('shareLabel')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="share-password" class="block text-xs font-medium text-[#6B6459]">Password for recipient</label>
                <input
                    id="share-password"
                    type="text"
                    wire:model="sharePassword"
                    autocomplete="off"
                    class="mt-1 w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm text-[#1E1E1E] focus:border-[#C9A227] focus:outline-none focus:ring-2 focus:ring-[#C9A227]/20"
                >
                @error('sharePassword')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="share-days" class="block text-xs font-medium text-[#6B6459]">Visible for (days)</label>
                <input
                    id="share-days"
                    type="number"
                    min="1"
                    max="90"
                    wire:model="shareDays"
                    class="mt-1 w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm text-[#1E1E1E] focus:border-[#C9A227] focus:outline-none focus:ring-2 focus:ring-[#C9A227]/20"
                >
                @error('shareDays')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-end">
                <button
                    type="submit"
                    class="w-full rounded-lg bg-[#1E1E1E] px-4 py-2.5 text-sm font-semibold text-white hover:bg-black disabled:opacity-60"
                    @disabled($sharesUnavailable ?? false)
                >
                    Create share link
                </button>
            </div>
        </form>

        @if ($createdShareUrl)
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            Link ready
                            @if ($createdShareLabel)
                                for {{ $createdShareLabel }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-emerald-900/80">Expires {{ $createdShareExpiresAt }} (Asia/Dhaka). Copy both link and password now — the password won’t be shown again.</p>
                    </div>
                    <button type="button" wire:click="dismissCreatedShare" class="text-xs font-medium text-emerald-900/70 hover:underline">Dismiss</button>
                </div>
                <div class="mt-3 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="min-w-0 flex-1 break-all font-mono text-xs">{{ $createdShareUrl }}</p>
                        <button
                            type="button"
                            wire:click="copyCreatedShareUrl"
                            class="shrink-0 rounded-full border border-emerald-300 bg-white px-3 py-1 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
                        >
                            Copy link
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs">Password: <span class="font-mono font-semibold">{{ $createdSharePassword }}</span></p>
                        <button
                            type="button"
                            wire:click="copyCreatedSharePassword"
                            class="shrink-0 rounded-full border border-emerald-300 bg-white px-3 py-1 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
                        >
                            Copy password
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if ($shares->isNotEmpty())
            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-[#EFE7D6] text-left text-xs text-[#8C8474]">
                            <th class="py-2 pr-4 font-medium">Recipient</th>
                            <th class="py-2 pr-4 font-medium">Created</th>
                            <th class="py-2 pr-4 font-medium">Expires</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shares as $share)
                            @php
                                $status = $share->isRevoked() ? 'Revoked' : ($share->isExpired() ? 'Expired' : 'Active');
                            @endphp
                            <tr class="border-b border-[#F5F0E6]" wire:key="share-{{ $share->id }}">
                                <td class="py-2.5 pr-4 text-[#1E1E1E]">
                                    {{ $share->label ?: 'Untitled' }}
                                    <div class="mt-0.5 font-mono text-[11px] text-[#8C8474]">…{{ substr($share->token, -8) }}</div>
                                </td>
                                <td class="py-2.5 pr-4 tabular-nums text-[#6B6459]">
                                    {{ $share->created_at->timezone('Asia/Dhaka')->format('d M Y') }}
                                    @if ($share->creator)
                                        <span class="text-[#8C8474]">· {{ $share->creator->name }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 tabular-nums text-[#6B6459]">
                                    {{ $share->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}
                                </td>
                                <td class="py-2.5 pr-4">
                                    <span @class([
                                        'text-xs font-medium',
                                        'text-[#2F6F4E]' => $status === 'Active',
                                        'text-[#8C8474]' => $status === 'Expired',
                                        'text-rose-700' => $status === 'Revoked',
                                    ])>{{ $status }}</span>
                                </td>
                                <td class="py-2.5 text-right">
                                    @if ($status === 'Active')
                                        <button
                                            type="button"
                                            wire:click="revokeShare({{ $share->id }})"
                                            wire:confirm="Revoke this share link? The recipient will lose access immediately."
                                            class="text-xs font-medium text-rose-700 hover:underline"
                                        >
                                            Revoke
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @include('livewire.partials.investor-pitch-deck-body', [
        'deck' => $deck,
        'years' => $years,
        'year' => $year,
        'showAdminLinks' => true,
    ])
</div>
