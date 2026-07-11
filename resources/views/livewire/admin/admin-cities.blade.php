<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Cities &amp; Areas</h1>
            <p class="text-sm text-[#6B6459] mt-1">Manage delivery cities and their areas.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.areas') }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                All areas
            </a>
            <a href="{{ route('admin.cities.create') }}" wire:navigate
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Create City
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ session('status') }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, division, slug…"
            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
        <select wire:model.live="status" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">City</th>
                        <th class="px-4 py-3 font-medium">Division</th>
                        <th class="px-4 py-3 font-medium">Areas</th>
                        <th class="px-4 py-3 font-medium">Flags</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($cities as $city)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="city-{{ $city->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $city->name }}</div>
                                <div class="text-xs text-[#8C8474]">{{ $city->slug ?: '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $city->division ?: '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $city->areas_count }}</td>
                            <td class="px-4 py-3">
                                @if ($city->is_dhaka)
                                    <span class="text-[10px] uppercase tracking-wide text-[#C9A227] font-semibold">Dhaka</span>
                                @else
                                    <span class="text-[#8C8474]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($city->is_active)
                                    <span class="text-emerald-700">Active</span>
                                @else
                                    <span class="text-[#8C8474]">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.areas', ['city' => $city->id]) }}" wire:navigate
                                    class="text-[#6B6459] hover:text-[#C9A227] hover:underline">Areas</a>
                                <a href="{{ route('admin.cities.edit', $city) }}" wire:navigate
                                    class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $city->id }})"
                                    wire:confirm="Delete “{{ $city->name }}”?{{ $city->areas_count > 0 ? ' This will also delete '.$city->areas_count.' areas.' : '' }}"
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-[#8C8474]">No cities yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($cities->hasPages())
        <div class="mt-4">{{ $cities->links() }}</div>
    @endif
</div>
