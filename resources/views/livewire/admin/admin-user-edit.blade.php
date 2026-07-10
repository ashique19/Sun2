<div>
    <a href="{{ route('admin.users.'.($role === 'moderator' ? 'moderators' : 'customers')) }}" wire:navigate
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
</div>
