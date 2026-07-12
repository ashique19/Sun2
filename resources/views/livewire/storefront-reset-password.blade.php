<x-storefront.shell>
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-2 text-center">Reset Password</h1>
        <p class="text-sm text-[#8C8474] text-center mb-8">Choose a new password for your account.</p>

        <form wire:submit="resetPassword" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" wire:model="email" readonly
                    class="w-full rounded-lg border border-[#E0D6C2] bg-[#FAF6EF] px-4 py-2 text-sm text-[#6B6459]">
                @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
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
                class="w-full rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                Reset Password
            </button>
        </form>
    </div>
</x-storefront.shell>
