<x-storefront.shell>
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-2 text-center">{{ __('storefront.forgot_password_title') }}</h1>
        <p class="text-sm text-[#8C8474] text-center mb-8">{{ __('storefront.forgot_password_subtitle') }}</p>

        @if ($statusMessage)
            <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $statusMessage }}</div>
        @endif
        @if ($formError)
            <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $formError }}</div>
        @endif

        @if ($step === 'request')
            <form wire:submit="sendReset" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('storefront.email_or_mobile') }}</label>
                    <input type="text" wire:model="identifier" placeholder="01XXXXXXXXX"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    @error('identifier') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-[#8C8474] mt-2">{{ __('storefront.email_mobile_hint') }}</p>
                </div>
                <button type="submit"
                    class="w-full rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                    {{ __('storefront.continue') }}
                </button>
            </form>
        @elseif ($step === 'email-sent' || $step === 'done')
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 text-center space-y-4">
                <p class="text-sm text-[#6B6459]">{{ $statusMessage }}</p>
                <a href="{{ route('login') }}" wire:navigate
                    class="inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                    {{ __('storefront.back_to_login') }}
                </a>
            </div>
        @elseif ($step === 'phone-otp')
            <form wire:submit="resetWithOtp" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('storefront.otp_code') }}</label>
                    <input type="text" wire:model="otp" maxlength="6" inputmode="numeric"
                        class="w-full tracking-[0.4em] text-center text-lg rounded-lg border border-[#E0D6C2] px-4 py-3 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    @error('otp') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    @if (app()->hasDebugModeEnabled())
                        <p class="text-xs text-[#8C8474] mt-1">Debug: OTP <strong>123456</strong></p>
                    @elseif (app()->environment('local'))
                        <p class="text-xs text-[#8C8474] mt-1">Local: check <code>storage/logs/laravel.log</code></p>
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('storefront.new_password') }}</label>
                    <input type="password" wire:model="password"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('storefront.confirm_new_password') }}</label>
                    <input type="password" wire:model="password_confirmation"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                </div>
                <button type="submit"
                    class="w-full rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                    {{ __('storefront.reset_password') }}
                </button>
            </form>
        @endif

        <p class="text-sm text-center text-[#6B6459] mt-6">
            <a href="{{ route('login') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.back_to_login') }}</a>
        </p>
    </div>
</x-storefront.shell>
