<div>
    @if ($status && ! $status['valid'])
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-4 sm:mb-6 sm:p-5" wire:key="fb-token-gate-invalid">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold text-rose-800">Facebook Page token needs attention</h2>
                    <p class="mt-1 text-sm text-rose-700">{{ $status['message'] }}</p>
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
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 sm:mb-6" wire:key="fb-token-gate-valid">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p>
                    {{ $feedback ? $feedback.' — ' : '' }}{{ $status['message'] }}
                </p>
                <button type="button"
                    wire:click="$toggle('showReplace')"
                    class="text-xs font-semibold text-emerald-800 underline decoration-emerald-400 underline-offset-2 hover:text-emerald-950">
                    {{ $showReplace ? 'Cancel' : 'Replace token' }}
                </button>
            </div>
            @if ($showReplace)
                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
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
            @endif
        </div>
    @endif
</div>
