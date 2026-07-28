<div>
    @if ($status && ! $status['valid'])
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-4 sm:mb-6 sm:p-5" wire:key="fb-token-gate-invalid">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold text-rose-800">Facebook Page token needs attention</h2>
                    <p class="mt-1 text-sm text-rose-700">{{ $status['message'] }}</p>
                    <p class="mt-2 text-xs text-rose-600/90">
                        Messenger replies and social publishing use this token. Generate a
                        <strong>Page</strong> access token (prefer a Business Manager System User token), then paste it below.
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

            @include('livewire.admin.partials.facebook-token-form', ['tone' => 'rose'])
        </div>
    @else
        <div class="mb-4 rounded-xl border border-[#E7DFCF] bg-white p-3 sm:mb-6 sm:p-4" wire:key="fb-token-gate-valid">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-[#1E1E1E]">Facebook Page token</p>
                    <p class="mt-0.5 text-xs text-[#8C8474]">
                        {{ $status['message'] ?? 'Token status unknown.' }}
                        @if ($feedback && $feedbackOk)
                            <span class="text-emerald-700">{{ $feedback }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                        wire:click="recheck"
                        class="rounded-full border border-[#E0D6C2] bg-[#FAF6EF] px-3 py-1.5 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                        Recheck
                    </button>
                    <button type="button"
                        wire:click="toggleUpdateForm"
                        class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1.5 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                        {{ $showUpdateForm ? 'Hide form' : 'Update token' }}
                    </button>
                </div>
            </div>

            @if ($showUpdateForm)
                <div class="mt-3 border-t border-[#EFE7D6] pt-3">
                    <p class="mb-2 text-xs text-[#8C8474]">
                        Paste a new Page access token if the current one expired. Prefer a long-lived Business Manager System User token.
                    </p>
                    <div class="mb-3 flex flex-wrap gap-2 text-xs">
                        <a href="{{ $generateTokenUrl }}" target="_blank" rel="noopener noreferrer"
                            class="font-semibold text-[#1877F2] hover:underline">Graph API Explorer</a>
                        <span class="text-[#C9BFA8]">·</span>
                        <a href="{{ $systemUserUrl }}" target="_blank" rel="noopener noreferrer"
                            class="font-semibold text-[#6B6459] hover:underline">Business System Users</a>
                    </div>
                    @include('livewire.admin.partials.facebook-token-form', ['tone' => 'neutral'])
                </div>
            @elseif ($feedback && ! $feedbackOk)
                <p class="mt-2 text-xs text-rose-700">{{ $feedback }}</p>
            @endif
        </div>
    @endif
</div>
