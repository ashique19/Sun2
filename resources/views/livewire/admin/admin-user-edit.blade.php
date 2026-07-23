<div>
    @php
        $backSegment = match (true) {
            $role === 'moderator' => 'moderators',
            $role === 'reseller' => 'resellers',
            default => 'customers',
        };
    @endphp
    <a href="{{ route('admin.users.'.$backSegment) }}" wire:navigate
        class="text-sm text-[#C9A227] hover:underline">&larr; Users</a>
    <h1 class="font-serif text-3xl font-semibold mt-2 mb-6">{{ $user?->name ?? 'Create User' }}</h1>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-2xl">
        <div class="grid sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Name</label>
                <input type="text" wire:model="name" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Phone</label>
                <input type="text" wire:model="phone" placeholder="01XXXXXXXXX" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" wire:model="email" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Role</label>
                <select wire:model="role" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="customers">Customer</option>
                    <option value="moderator">Moderator</option>
                    <option value="reseller">Reseller</option>
                </select>
                @error('role') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_active" class="rounded border-[#E0D6C2] text-[#C9A227]">
                    Active
                </label>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Password {{ $user ? '(optional)' : '' }}</label>
                <input type="password" wire:model="password" autocomplete="new-password"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Confirm password</label>
                <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $user ? 'Save User' : 'Create User' }}
            </button>
            @if ($user && $canDelete)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this user?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @elseif ($user)
                <p class="text-xs text-[#8C8474]">Delete is disabled for your own account or when orders still reference this user.</p>
            @endif
        </div>
    </form>

    @if ($user && $isReseller)
        <div class="mt-8 rounded-xl border border-[#EFE7D6] bg-white p-6 max-w-2xl space-y-4">
            <div>
                <h2 class="font-semibold text-lg">Reseller wallet</h2>
                <p class="text-sm text-[#8C8474] mt-1">Record a payout to reduce this reseller&apos;s available balance.</p>
            </div>

            <div class="rounded-lg bg-[#FAF6EF] px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-[#8C8474]">Available balance</p>
                <p class="text-2xl font-semibold tabular-nums mt-1">&#2547; {{ number_format($resellerBalance, 0) }}</p>
            </div>

            <form wire:submit="recordPayout" class="space-y-4">
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Payout amount (&#2547;)</label>
                        <input type="number" min="1" step="1" wire:model="payoutAmount"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        @error('payoutAmount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1">Note (optional)</label>
                        <input type="text" wire:model="payoutNote" placeholder="e.g. bKash 01XXXXXXXXX"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        @error('payoutNote') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit"
                    class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-50"
                    @disabled($resellerBalance <= 0)>
                    Record payout
                </button>
            </form>
        </div>
    @endif
</div>
