<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Products</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Double-click price, cost, or stock to edit inline.</p>
        </div>
        <a href="{{ route('admin.products.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Product
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, SKU, price…"
            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
        <select wire:model.live="category" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All categories</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="published" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All</option>
            <option value="1">Published</option>
            <option value="0">Draft</option>
        </select>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Cost</th>
                        <th class="px-4 py-3 font-medium">Max disc.</th>
                        <th class="px-4 py-3 font-medium">Stock</th>
                        <th class="px-4 py-3 font-medium">Published</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($products as $product)
                        <tr wire:key="product-row-{{ $product->id }}" class="hover:bg-[#FAF6EF]/60">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @php $thumb = $product->images->first()?->path @endphp
                                    @if ($thumb)
                                        <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="w-10 h-10 rounded object-cover bg-[#FAF6EF]">
                                    @endif
                                    <div>
                                        <div class="font-medium line-clamp-1">{{ $product->name }}</div>
                                        <div class="text-xs text-[#8C8474]">{{ $product->sku ?: $product->slug }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>

                            @foreach ([
                                'price' => ['value' => (string) (int) round((float) $product->price), 'prefix' => '৳ '],
                                'purchase_price' => ['value' => (string) (int) round((float) $product->purchase_price), 'prefix' => '৳ '],
                            ] as $field => $cell)
                                <td
                                    class="px-4 py-3 tabular-nums {{ $editingProductId === $product->id && $editingField === $field ? '' : 'cursor-pointer select-none' }}"
                                    title="Double-click to edit"
                                    @if (! ($editingProductId === $product->id && $editingField === $field))
                                        wire:dblclick="startInlineEdit({{ $product->id }}, '{{ $field }}', '{{ $cell['value'] }}')"
                                    @endif
                                >
                                    @if ($editingProductId === $product->id && $editingField === $field)
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            wire:model="editingValue"
                                            wire:keydown.enter.prevent="saveInlineEdit"
                                            wire:keydown.escape.prevent="cancelInlineEdit"
                                            wire:blur="saveInlineEdit"
                                            x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                            class="w-24 rounded-lg border border-[#C9A227] bg-white px-2 py-1 text-sm tabular-nums shadow-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                                            aria-label="Edit {{ str_replace('_', ' ', $field) }}"
                                        >
                                        @error('editingValue')
                                            <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                        @enderror
                                    @else
                                        {{ $cell['prefix'] }}{{ number_format((float) $cell['value'], 0) }}
                                    @endif
                                </td>
                            @endforeach

                            <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                @if ($product->max_discount !== null)
                                    ৳ {{ number_format((float) $product->max_discount, 0) }}
                                @else
                                    <span class="text-[#8C8474]">—</span>
                                @endif
                            </td>

                            @php($stockCell = ['value' => (string) (int) $product->stock_quantity, 'prefix' => ''])
                            <td
                                class="px-4 py-3 tabular-nums {{ $editingProductId === $product->id && $editingField === 'stock_quantity' ? '' : 'cursor-pointer select-none' }}"
                                title="Double-click to edit"
                                @if (! ($editingProductId === $product->id && $editingField === 'stock_quantity'))
                                    wire:dblclick="startInlineEdit({{ $product->id }}, 'stock_quantity', '{{ $stockCell['value'] }}')"
                                @endif
                            >
                                @if ($editingProductId === $product->id && $editingField === 'stock_quantity')
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        wire:model="editingValue"
                                        wire:keydown.enter.prevent="saveInlineEdit"
                                        wire:keydown.escape.prevent="cancelInlineEdit"
                                        wire:blur="saveInlineEdit"
                                        x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                        class="w-24 rounded-lg border border-[#C9A227] bg-white px-2 py-1 text-sm tabular-nums shadow-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                                        aria-label="Edit stock quantity"
                                    >
                                    @error('editingValue')
                                        <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    {{ $stockCell['prefix'] }}{{ number_format((float) $stockCell['value'], 0) }}
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <button type="button" wire:click="togglePublished({{ $product->id }})"
                                    class="text-xs rounded-full px-2.5 py-1 {{ $product->is_published ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $product->is_published ? 'Yes' : 'No' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.products.performance', $product) }}" target="_blank"
                                    class="text-[#6B6459] hover:text-[#C9A227] hover:underline">Performance</a>
                                <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $product->id }})"
                                    wire:confirm="Delete “{{ $product->name }}”? This cannot be undone."
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-[#8C8474]">No products found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="px-4 py-3 border-t border-[#E7DFCF]">{{ $products->links() }}</div>
        @endif
    </div>
</div>
