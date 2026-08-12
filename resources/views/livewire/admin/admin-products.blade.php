<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Products</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Double-click price, regular price, cost, commission, max discount, or stock to edit inline.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="openBulkStock" @disabled($selected === [])
                class="rounded-full px-5 py-2 text-sm font-semibold transition border
                    {{ $selected === []
                        ? 'border-[#E0D6C2] text-[#B0A898] cursor-not-allowed bg-white'
                        : 'border-[#C9A227] text-[#C9A227] bg-white hover:bg-[#FAF6EF]' }}">
                Change stock ({{ count($selected) }})
            </button>
            <button type="button" wire:click="makePost" @disabled($selected === [])
                class="rounded-full px-5 py-2 text-sm font-semibold text-white transition
                    {{ $selected === [] ? 'bg-[#D8CDB6] cursor-not-allowed' : 'bg-[#C9A227] hover:bg-[#b8931f]' }}">
                Make post ({{ count($selected) }})
            </button>
            <a href="{{ route('admin.products.create') }}" wire:navigate
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Create Product
            </a>
        </div>
    </div>

    @if ($bulkStockOpen)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-4 flex flex-wrap items-end gap-3"
            role="dialog"
            aria-label="Change stock quantity for selected products">
            <div class="min-w-[10rem]">
                <label for="bulk-stock-quantity" class="block text-xs font-medium text-[#6B6459] mb-1">
                    New stock quantity for {{ count($selected) }} selected
                </label>
                <input id="bulk-stock-quantity"
                    type="number"
                    min="0"
                    step="1"
                    inputmode="numeric"
                    wire:model="bulkStockQuantity"
                    wire:keydown.enter.prevent="applyBulkStock"
                    class="w-36 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums"
                    placeholder="e.g. 10"
                    autofocus>
                @if ($errors->has('bulkStockQuantity'))
                    <p class="mt-1 text-[11px] text-rose-600">{{ $errors->first('bulkStockQuantity') }}</p>
                @endif
            </div>
            <button type="button" wire:click="applyBulkStock"
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Apply
            </button>
            <button type="button" wire:click="closeBulkStock"
                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                Cancel
            </button>
        </div>
    @endif

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @error('pricedImage')
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @enderror

    <x-admin.product-list-filters :categories="$categories" />

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium text-center">Post</th>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Regular</th>
                        <th class="px-4 py-3 font-medium">Main</th>
                        <th class="px-4 py-3 font-medium">Unit cost</th>
                        <th class="px-4 py-3 font-medium">Commission</th>
                        <th class="px-4 py-3 font-medium">Max disc.</th>
                        <th class="px-4 py-3 font-medium">Stock</th>
                        <th class="px-4 py-3 font-medium">Published</th>
                        <th class="px-4 py-3 font-medium min-w-[9rem]">Priced image</th>
                        <th class="px-4 py-3 font-medium min-w-[9rem]"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($products as $product)
                        <tr wire:key="product-row-{{ $product->id }}" class="hover:bg-[#FAF6EF]/60">
                            <td class="px-4 py-3 text-center">
                                <input type="checkbox"
                                    wire:click="toggleSelected({{ $product->id }})"
                                    @checked(in_array($product->id, $selected, true))
                                    class="h-4 w-4 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]/40">
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @php $thumb = $product->images->first()?->path @endphp
                                    @if ($thumb)
                                        <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="h-[7.5rem] w-[7.5rem] shrink-0 rounded object-cover bg-[#FAF6EF]">
                                    @endif
                                    <div>
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <a href="{{ route('admin.products.show', $product) }}" wire:navigate
                                                class="font-medium line-clamp-1 text-[#C9A227] hover:underline">
                                                {{ $product->name }}
                                            </a>
                                            <button type="button"
                                                x-data="{ copied: false }"
                                                data-copy-text="{{ route('product.show', $product) }}"
                                                data-copy-public-link
                                                x-on:click="
                                                    window.sunCopyText($el.dataset.copyText).then((ok) => {
                                                        if (! ok) return;
                                                        copied = true;
                                                        setTimeout(() => copied = false, 2000);
                                                    })
                                                "
                                                class="shrink-0 text-[11px] font-medium text-[#8C8474] underline-offset-2 hover:text-[#C9A227] hover:underline"
                                                title="Copy storefront product URL">
                                                <span x-text="copied ? 'Copied' : 'Copy public link'">Copy public link</span>
                                            </button>
                                        </div>
                                        <div class="text-xs text-[#8C8474]">{{ $product->sku ?: $product->slug }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>

                            @foreach ([
                                'price' => ['value' => (string) (int) round((float) $product->price), 'prefix' => '৳ ', 'nullable' => false],
                                'compare_at_price' => [
                                    'value' => $product->compare_at_price !== null ? (string) (int) round((float) $product->compare_at_price) : '',
                                    'prefix' => '৳ ',
                                    'nullable' => true,
                                ],
                                'purchase_price' => ['value' => (string) (int) round((float) $product->purchase_price), 'prefix' => '৳ ', 'nullable' => false],
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
                                            @if ($cell['nullable']) placeholder="—" @endif
                                        >
                                        @error('editingValue')
                                            <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                        @enderror
                                    @else
                                        {{ $cell['prefix'] }}{{ $cell['value'] !== '' ? number_format((float) $cell['value'], 0) : '—' }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-4 py-3 tabular-nums text-[#6B6459]" title="Total unit cost (COGS)">
                                ৳ {{ number_format($product->effectiveUnitCost(), 0) }}
                            </td>
                            @foreach ([
                                'commission' => ['value' => (string) (int) round((float) $product->commission), 'prefix' => '৳ ', 'nullable' => false],
                                'max_discount' => [
                                    'value' => $product->max_discount !== null ? (string) (int) round((float) $product->max_discount) : '',
                                    'prefix' => '৳ ',
                                    'nullable' => true,
                                ],
                            ] as $field => $cell)
                                <td
                                    class="px-4 py-3 tabular-nums {{ $editingProductId === $product->id && $editingField === $field ? '' : 'cursor-pointer select-none' }} {{ $field === 'commission' || $field === 'max_discount' ? 'text-[#6B6459]' : '' }}"
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
                                            @if ($cell['nullable']) placeholder="—" @endif
                                        >
                                        @error('editingValue')
                                            <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                        @enderror
                                    @elseif ($cell['nullable'] && $cell['value'] === '')
                                        <span class="text-[#8C8474]">—</span>
                                    @else
                                        {{ $cell['prefix'] }}{{ number_format((float) $cell['value'], 0) }}
                                    @endif
                                </td>
                            @endforeach

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
                            <td class="px-4 py-3 align-middle">
                                <div class="flex flex-col items-start gap-2 min-w-[7.5rem]">
                                    @if ($product->priced_image_path)
                                        <a href="{{ route('admin.products.edit', $product) }}" wire:navigate
                                            class="shrink-0"
                                            title="View priced image">
                                            <img src="{{ \App\Support\StorefrontAssets::url($product->priced_image_path) }}"
                                                alt="Priced image for {{ $product->name }}"
                                                class="h-[7.5rem] w-[7.5rem] shrink-0 rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                        </a>
                                    @endif
                                    <button type="button"
                                        wire:click="generatePricedImage({{ $product->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="generatePricedImage({{ $product->id }})"
                                        class="rounded border border-[#C9A227] px-2 py-1 text-xs text-[#C9A227] hover:bg-[#FAF6EF] disabled:opacity-60 whitespace-nowrap">
                                        <span wire:loading.remove wire:target="generatePricedImage({{ $product->id }})">
                                            {{ $product->priced_image_path ? 'Rebuild' : 'Put price on image' }}
                                        </span>
                                        <span wire:loading wire:target="generatePricedImage({{ $product->id }})">Working…</span>
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right align-middle whitespace-nowrap">
                                <div class="inline-flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.products.show', $product) }}" wire:navigate
                                        class="text-[#6B6459] hover:text-[#C9A227] hover:underline">View</a>
                                    <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                    <button type="button"
                                        wire:click="delete({{ $product->id }})"
                                        wire:confirm="Delete “{{ $product->name }}”? This cannot be undone."
                                        class="text-rose-600 hover:underline">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="px-4 py-8 text-center text-[#8C8474]">No products found.</td>
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
