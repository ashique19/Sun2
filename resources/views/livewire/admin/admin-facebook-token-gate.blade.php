<div>
    @if ($status && ! $status['valid'])
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-4 sm:mb-6 sm:p-5" wire:key="fb-token-gate-invalid">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold text-rose-800">Facebook Page token needs attention</h2>
                    <p class="mt-1 text-sm text-rose-700">{{ $status['message'] }}</p>
                    @if (! empty($status['expires_label']))
                        <p class="mt-0.5 text-xs text-rose-600/80">{{ $status['expires_label'] }}</p>
                    @endif
                    <p class="mt-2 text-xs text-rose-600/90">
                        Paste the current Graph access token (same as today). With
                        <code class="rounded bg-white/70 px-1">FACEBOOK_APP_ID</code> +
                        <code class="rounded bg-white/70 px-1">FACEBOOK_APP_SECRET</code>
                        we exchange it for a <strong>long-lived token</strong> (~60 days), save it on this server,
                        and use it for Messenger. A User token is converted into a Page token and kept for automatic refresh.
                    </p>
                </div>
                <button type="button"
                    wire:click="recheck"
                    class="rounded-full border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                    Recheck
                </button>
            </div>

            <div class="mt-4 flex flex-wrap gap-3 text-sm">
                <a href="{{ $generateTokenUrl }}" target="_blank" rel="noopener noreferrer"
                    class="inline-flex items-center rounded-full bg-[#1877F2] px-4 py-2 font-semibold text-white hover:bg-[#166FE5]">
                    Open Graph API Explorer
                </a>
                <a href="{{ $systemUserUrl }}" target="_blank" rel="noopener noreferrer"
                    class="inline-flex items-center rounded-full border border-rose-300 bg-white px-4 py-2 font-semibold text-rose-800 hover:bg-rose-100">
                    Business System Users
                </a>
            </div>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start">
                <input type="password"
                    wire:model="tokenInput"
                    autocomplete="off"
                    placeholder="Paste User or Page access token"
                    class="min-w-0 flex-1 rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-[#1E1E1E] focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-400">
                <button type="button"
                    wire:click="saveToken"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-60">
                    Save token
                </button>
            </div>

            @if ($feedback)
                <p class="mt-2 text-xs {{ $feedbackOk ? 'text-emerald-700' : 'text-rose-700' }}">{{ $feedback }}</p>
            @endif
        </div>
    @elseif ($status && $status['valid'])
        <div class="mb-2 flex flex-col items-end sm:mb-3" wire:key="fb-token-gate-valid">
            <button type="button"
                wire:click="$toggle('showReplace')"
                class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
                aria-expanded="{{ $showReplace ? 'true' : 'false' }}"
                aria-label="Facebook token"
                title="{{ $status['expires_label'] ?: 'Facebook token OK' }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                    <path d="M22 12.07C22 6.48 17.52 2 11.93 2S1.86 6.48 1.86 12.07c0 5.02 3.66 9.18 8.44 9.93v-7.02H7.9v-2.91h2.4V9.84c0-2.37 1.4-3.69 3.56-3.69 1.03 0 2.12.18 2.12.18v2.34h-1.2c-1.18 0-1.54.73-1.54 1.48v1.78h2.63l-.42 2.91h-2.21V22c4.78-.75 8.44-4.91 8.44-9.93Z" />
                </svg>
            </button>
            @if ($showReplace)
                <div class="mt-2 w-full rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <input type="password"
                            wire:model="tokenInput"
                            autocomplete="off"
                            placeholder="Paste current User or Page access token"
                            class="min-w-0 flex-1 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm text-[#1E1E1E] focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-400">
                        <button type="button"
                            wire:click="saveToken"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-60">
                            Exchange &amp; save
                        </button>
                    </div>
                    @if ($feedback)
                        <p class="mt-2 text-xs {{ $feedbackOk ? 'text-emerald-700' : 'text-rose-700' }}">{{ $feedback }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
