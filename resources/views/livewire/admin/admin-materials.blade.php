<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Materials</h1>
            <p class="mt-1 text-sm text-[#8C8474]">Inventory inputs for product cost. Purchases update stock — not Expenses.</p>
        </div>
        <a href="{{ route('admin.materials.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Material
        </a>
    </div>

    @if ($error)
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>
    @endif

    <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
        <table class="w-full text-sm">
            <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                <tr>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">SKU</th>
                    <th class="px-4 py-3 font-medium">Unit</th>
                    <th class="px-4 py-3 font-medium">Unit cost</th>
                    <th class="px-4 py-3 font-medium">Stock</th>
                    <th class="px-4 py-3 font-medium">Products</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E7DFCF]">
                @forelse ($materials as $material)
                    <tr class="hover:bg-[#FAF6EF]/60" wire:key="material-{{ $material->id }}">
                        <td class="px-4 py-3 font-medium">{{ $material->name }}</td>
                        <td class="px-4 py-3 text-[#8C8474]">{{ $material->sku ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $material->unit }}</td>
                        <td class="px-4 py-3 tabular-nums">৳{{ number_format((float) $material->unit_cost, 2) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ rtrim(rtrim(number_format((float) $material->stock_quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3">{{ $material->products_count }}</td>
                        <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('admin.materials.edit', $material) }}" wire:navigate
                                class="text-[#C9A227] hover:underline">Edit</a>
                            @if ($material->products_count === 0)
                                <button type="button"
                                    wire:click="delete({{ $material->id }})"
                                    wire:confirm="Delete “{{ $material->name }}”? This cannot be undone."
                                    class="text-rose-600 hover:underline">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[#8C8474]">No materials yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
