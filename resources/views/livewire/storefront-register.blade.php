<x-storefront.shell>
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-2 text-center">{{ __('storefront.create_account') }}</h1>
        <p class="text-sm text-[#8C8474] text-center mb-8">{{ __('storefront.join_tagline') }}</p>

        <form wire:submit="register" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.full_name') }}</label>
                <input type="text" wire:model="name"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.mobile') }}</label>
                <input type="tel" wire:model="phone" placeholder="01XXXXXXXXX"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.email_label') }} <span class="font-normal text-[#8C8474]">({{ __('storefront.optional') }})</span></label>
                <input type="email" wire:model="email" placeholder="you@example.com"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.password') }}</label>
                <input type="password" wire:model="password"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('storefront.confirm_password') }}</label>
                <input type="password" wire:model="password_confirmation"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
            </div>
            <button type="submit"
                class="w-full rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                {{ __('storefront.sign_up') }}
            </button>
        </form>

        <p class="text-sm text-center text-[#6B6459] mt-6">
            {{ __('storefront.already_have_account') }}
            <a href="{{ route('login') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.log_in_link') }}</a>
        </p>
    </div>
</x-storefront.shell>
