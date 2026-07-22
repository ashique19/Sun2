<x-storefront.shell>
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-2 text-center">{{ __('storefront.login_title') }}</h1>
        <p class="text-sm text-[#8C8474] text-center mb-8">{{ __('storefront.login_subtitle') }}</p>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ session('status') }}</div>
        @endif

        <form wire:submit="login" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.email_or_mobile') }}</label>
                <input type="text" wire:model="identifier" placeholder="01XXXXXXXXX"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('identifier') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.password') }}</label>
                <input type="password" wire:model="password"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-[#6B6459]">
                    <input type="checkbox" wire:model="remember" class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                    {{ __('storefront.remember_me') }}
                </label>
                <a href="{{ route('password.request') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.forgot_password') }}</a>
            </div>
            <button type="submit"
                class="w-full rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                {{ __('storefront.login') }}
            </button>
        </form>

        <p class="text-sm text-center text-[#6B6459] mt-6">
            {{ __('storefront.new_here') }}
            <a href="{{ route('register') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.create_an_account') }}</a>
        </p>
    </div>
</x-storefront.shell>
