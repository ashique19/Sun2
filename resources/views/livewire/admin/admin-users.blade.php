<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 class="font-serif text-3xl font-semibold">{{ $segmentLabel }}</h1>
        <a href="{{ route('admin.users.create', ['role' => $roleName]) }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            @if ($segment === 'moderators') Create Moderator
            @elseif ($segment === 'resellers') Create Reseller
            @elseif ($segment === 'admins') Create Admin
            @else Create Customer
            @endif
        </a>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        @foreach ($segments as $segmentKey => $segmentName)
            <button type="button"
                wire:click="switchSegment('{{ $segmentKey }}')"
                wire:loading.attr="disabled"
                class="rounded-full px-4 py-1.5 text-sm border transition disabled:opacity-60 {{ $segment === $segmentKey ? 'border-[#C9A227] bg-[#C9A227] text-white font-medium' : 'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                {{ $segmentName }}
            </button>
        @endforeach
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 space-y-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, phone, email…"
            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        @if ($segment === 'customers')
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11px] font-medium uppercase tracking-wide text-[#8C8474] mb-1">Lifetime orders from</label>
                    <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="ordersMin"
                        placeholder="e.g. 0"
                        class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                </div>
                <div>
                    <label class="block text-[11px] font-medium uppercase tracking-wide text-[#8C8474] mb-1">to</label>
                    <input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="ordersMax"
                        placeholder="e.g. 1"
                        class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                </div>
                <p class="pb-2 text-xs text-[#8C8474]">Leave blank for no limit.</p>
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        @if ($segment === 'customers')
                            <th class="px-4 py-3 font-medium text-right">Orders</th>
                        @endif
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Joined</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($users as $user)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3 font-medium">
                                @if ($segment === 'customers')
                                    <a href="{{ route('admin.customers.show', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">{{ $user->name }}</a>
                                @else
                                    {{ $user->name }}
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ $user->phone }}</td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $user->email ?: '—' }}</td>
                            @if ($segment === 'customers')
                                <td class="px-4 py-3 text-right tabular-nums font-medium">{{ number_format((int) ($user->orders_count ?? 0)) }}</td>
                            @endif
                            <td class="px-4 py-3">
                                <button type="button" wire:click="toggleActive({{ $user->id }})"
                                    @disabled((int) $user->id === (int) auth()->id())
                                    class="text-xs rounded-full px-2.5 py-1 disabled:opacity-40 {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $user->is_active ? 'Active' : 'Off' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-[#6B6459] whitespace-nowrap">{{ $user->created_at?->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                @if ($segment === 'customers')
                                    <a href="{{ route('admin.customers.show', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">View</a>
                                @endif
                                <a href="{{ route('admin.users.edit', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                @if ((int) $user->id !== (int) auth()->id())
                                    <button type="button"
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Delete {{ $user->name }}?"
                                        class="text-rose-600 hover:underline">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $segment === 'customers' ? 7 : 6 }}" class="px-4 py-8 text-center text-[#8C8474]">
                                No {{ strtolower($segmentLabel) }} yet.
                                @if ($segment === 'resellers')
                                    <a href="{{ route('admin.users.create', ['role' => 'reseller']) }}" wire:navigate class="text-[#C9A227] hover:underline">Create one</a>.
                                @elseif ($segment === 'admins')
                                    <a href="{{ route('admin.users.create', ['role' => 'admin']) }}" wire:navigate class="text-[#C9A227] hover:underline">Create one</a>.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-[#E7DFCF]">{{ $users->links() }}</div>
        @endif
    </div>
</div>
