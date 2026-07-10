<div>
    <x-storefront.announcement />
    <x-storefront.header />

    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">Change Password</h1>

        <div class="grid lg:grid-cols-4 gap-8 items-start">
            <div class="lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3">
                <form wire:submit="updatePassword" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-xl">
                    @if ($statusMessage)
                        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3">{{ $statusMessage }}</div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium mb-1">Current Password</label>
                        <input type="password" wire:model="current_password"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('current_password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">New Password</label>
                        <input type="password" wire:model="password"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Confirm New Password</label>
                        <input type="password" wire:model="password_confirmation"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    </div>
                    <button type="submit"
                        class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <x-storefront.footer />
</div>
