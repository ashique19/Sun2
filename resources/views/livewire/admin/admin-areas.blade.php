<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <a href="{{ route('admin.cities') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Cities</a>
            <h1 class="font-serif text-3xl font-semibold mt-2">Areas</h1>
        </div>
        <a href="{{ route('admin.areas.create', array_filter(['city' => $city !== '' ? $city : null])) }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Area
        </a>
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
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, station, slug…"
            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
        <select wire:model.live="city" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All cities</option>
            @foreach ($cities as $cityOption)
                <option value="{{ $cityOption->id }}">{{ $cityOption->name }}</option>
            @endforeach
        </select>
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
                        <th class="px-4 py-3 font-medium">Area</th>
                        <th class="px-4 py-3 font-medium">City</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Delivery ≤5</th>
                        <th class="px-4 py-3 font-medium">Delivery &gt;5</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($areas as $area)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="area-{{ $area->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $area->name }}</div>
                                <div class="text-xs text-[#8C8474]">{{ $area->slug ?: '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $area->city?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $area->unit_type ?: '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">&#2547; {{ number_format($area->delivery_charge_upto_5, 0) }}</td>
                            <td class="px-4 py-3 tabular-nums">&#2547; {{ number_format($area->delivery_charge_over_5, 0) }}</td>
                            <td class="px-4 py-3">
                                @if ($area->is_active)
                                    <span class="text-emerald-700">Active</span>
                                @else
                                    <span class="text-[#8C8474]">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.areas.edit', $area) }}" wire:navigate
                                    class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $area->id }})"
                                    wire:confirm="Delete “{{ $area->name }}”?"
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-[#8C8474]">No areas yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($areas->hasPages())
        <div class="mt-4">{{ $areas->links() }}</div>
    @endif
</div>
