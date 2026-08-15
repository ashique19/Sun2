<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Products</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Double-click price, regular price, cost, commission, max discount, or stock to edit inline.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="openBulkCategory" @disabled($selected === [])
                class="rounded-full px-5 py-2 text-sm font-semibold transition border
                    {{ $selected === []
                        ? 'border-[#E0D6C2] text-[#B0A898] cursor-not-allowed bg-white'
                        : 'border-[#C9A227] text-[#C9A227] bg-white hover:bg-[#FAF6EF]' }}">
                Change category ({{ count($selected) }})
            </button>
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
            <button type="button" wire:click="openBulkAiGenerateModal" @disabled($selected === [])
                class="rounded-full px-5 py-2 text-sm font-semibold transition border
                    {{ $selected === []
                        ? 'border-[#E0D6C2] text-[#B0A898] cursor-not-allowed bg-white'
                        : 'border-[#C9A227] text-[#C9A227] bg-white hover:bg-[#FAF6EF]' }}">
                Generate image with AI ({{ count($selected) }})
            </button>
            <button type="button" wire:click="openPutPriceModal"
                class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-semibold text-[#C9A227] bg-white hover:bg-[#FAF6EF]">
                Put price
            </button>
            <a href="{{ route('admin.products.create') }}" wire:navigate
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Create Product
            </a>
        </div>
    </div>

    @if ($bulkCategoryOpen)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-4 flex flex-wrap items-end gap-3"
            role="dialog"
            aria-label="Change category for selected products">
            <div class="min-w-[14rem]">
                <label for="bulk-category-id" class="block text-xs font-medium text-[#6B6459] mb-1">
                    New category for {{ count($selected) }} selected
                </label>
                <select id="bulk-category-id"
                    wire:model="bulkCategoryId"
                    class="w-full min-w-[14rem] rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                    <option value="">Select category…</option>
                    @foreach ($categories as $categoryOption)
                        <option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</option>
                    @endforeach
                </select>
                @if ($errors->has('bulkCategoryId'))
                    <p class="mt-1 text-[11px] text-rose-600">{{ $errors->first('bulkCategoryId') }}</p>
                @endif
            </div>
            <button type="button" wire:click="applyBulkCategory"
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Apply
            </button>
            <button type="button" wire:click="closeBulkCategory"
                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                Cancel
            </button>
        </div>
    @endif

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
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
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
                                                class="my-0.5 ml-1 shrink-0 rounded-md border border-[#E0D6C2] bg-white px-2 py-1 text-[11px] font-semibold text-[#1E1E1E] hover:border-[#C9A227] hover:text-[#C9A227]"
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

    @teleport('body')
        <div wire:key="put-price-modal-host">
            @if ($putPriceModalOpen)
                <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
                    wire:click.self="closePutPriceModal"
                    wire:key="put-price-modal"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Put price on images">
                    <div class="flex max-h-[min(90dvh,40rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                        wire:click.stop>
                        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                            <div>
                                <h2 class="font-semibold text-lg">Put price</h2>
                                <p class="mt-0.5 text-xs text-[#8C8474]">
                                    Centered, semi-blur overlay at 20% of the primary image. Rebuild later to tweak.
                                </p>
                            </div>
                            <button type="button" wire:click="closePutPriceModal"
                                class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                                Close
                            </button>
                        </div>

                        <div class="max-h-[min(22rem,calc(90dvh-11rem))] overflow-y-auto px-4 py-3 space-y-3">
                            @if ($putPriceMessage)
                                <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3">{{ $putPriceMessage }}</div>
                            @endif
                            @if ($putPriceErrors !== [])
                                <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 space-y-1">
                                    @foreach ($putPriceErrors as $error)
                                        <p>{{ $error }}</p>
                                    @endforeach
                                </div>
                            @endif

                            @if ($putPriceBatch === [])
                                <p class="text-sm text-[#6B6459]">
                                    @if ($putPriceReplaceExisting)
                                        {{ $putPriceTotalSaved > 0
                                            ? 'Finished pricing the selected products.'
                                            : 'No selected products with photos to price.' }}
                                    @else
                                        Every product with a photo already has a priced image.
                                    @endif
                                </p>
                            @else
                                <p class="text-sm text-[#6B6459]">
                                    @if ($putPriceReplaceExisting)
                                        Showing {{ count($putPriceBatch) }} of {{ $putPriceRemaining }} selected
                                        (replaces existing priced images).
                                    @else
                                        Showing {{ count($putPriceBatch) }} of {{ $putPriceRemaining }} without a priced image.
                                    @endif
                                </p>
                                <ul class="grid grid-cols-5 gap-2">
                                    @foreach ($putPriceBatch as $row)
                                        <li wire:key="put-price-{{ $row['id'] }}" class="min-w-0">
                                            @if ($row['thumb'])
                                                <img src="{{ \App\Support\StorefrontAssets::url($row['thumb']) }}"
                                                    alt=""
                                                    class="aspect-square w-full rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                            @else
                                                <div class="aspect-square w-full rounded border border-[#E7DFCF] bg-[#FAF6EF]"></div>
                                            @endif
                                            <p class="mt-1 truncate text-xs font-medium text-[#1E1E1E]" title="{{ $row['name'] }}">{{ $row['name'] }}</p>
                                            <p class="text-[11px] tabular-nums text-[#8C8474]">Tk {{ $row['price'] }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                            <button type="button" wire:click="closePutPriceModal"
                                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                                {{ $putPriceBatch === [] ? 'Done' : 'Cancel' }}
                            </button>
                            @if ($putPriceBatch !== [])
                                <button type="button"
                                    wire:click="applyPutPriceBatch"
                                    wire:loading.attr="disabled"
                                    wire:target="applyPutPriceBatch"
                                    @disabled($putPriceRunning)
                                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                    <span wire:loading.remove wire:target="applyPutPriceBatch">
                                        {{ $putPriceRunning ? 'Saving next…' : 'Put price & next' }}
                                    </span>
                                    <span wire:loading wire:target="applyPutPriceBatch">Saving…</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div wire:key="bulk-ai-generate-modal-host">
            @if ($bulkAiModalOpen)
                <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
                    @unless ($bulkAiRunning) wire:click.self="closeBulkAiGenerateModal" @endunless
                    wire:key="bulk-ai-generate-modal"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Generate images with AI">
                    <div class="flex max-h-[min(90dvh,42rem)] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                        wire:click.stop>
                        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                            <div>
                                <h2 class="font-semibold text-lg">Generate image with AI</h2>
                                <p class="mt-0.5 text-xs text-[#8C8474]">
                                    Runs the selected prompt sequence on each product’s photo, one at a time.
                                    Every step is saved as an admin-only image.
                                </p>
                            </div>
                            @unless ($bulkAiRunning)
                                <button type="button" wire:click="closeBulkAiGenerateModal"
                                    class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                                    Close
                                </button>
                            @endunless
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 space-y-3">
                            @unless ($geminiConfigured)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    Gemini is not configured. Set <code class="text-xs">GEMINI_API_KEY</code> before running.
                                </div>
                            @endunless

                            @if ($bulkAiMessage)
                                <div @class([
                                    'rounded-lg text-sm px-4 py-3',
                                    'bg-emerald-50 text-emerald-700' => ! $bulkAiRunning && str_starts_with($bulkAiMessage, 'Finished'),
                                    'bg-[#FAF6EF] text-[#6B6459]' => $bulkAiRunning || ! str_starts_with($bulkAiMessage, 'Finished'),
                                ])>{{ $bulkAiMessage }}</div>
                            @endif

                            @if ($bulkAiRows === [])
                                <div>
                                    <label class="block text-sm font-medium mb-1">AI prompt sequence</label>
                                    @if ($aiPromptGroups->isEmpty())
                                        <p class="text-sm text-[#6B6459]">
                                            No prompt sequences yet.
                                            <a href="{{ route('admin.ai-prompts') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Create one</a>
                                            first.
                                        </p>
                                    @else
                                        <ul class="space-y-2">
                                            @foreach ($aiPromptGroups as $group)
                                                @php($stepCount = (int) ($group->prompts_count ?? $group->prompts->count()))
                                                <li>
                                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition
                                                        {{ (int) $bulkAiPromptGroupId === $group->id
                                                            ? 'border-[#C9A227] bg-[#FAF6EF]'
                                                            : 'border-[#EFE7D6] hover:border-[#C9A227]/60' }}">
                                                        <input type="radio"
                                                            wire:model.live="bulkAiPromptGroupId"
                                                            value="{{ $group->id }}"
                                                            class="mt-1 border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]"
                                                            @disabled($bulkAiRunning)>
                                                        <span class="min-w-0">
                                                            <span class="block text-sm font-medium text-[#1E1E1E]">{{ $group->name }}</span>
                                                            <span class="mt-0.5 block text-xs text-[#8C8474]">
                                                                {{ $stepCount }} {{ $stepCount === 1 ? 'step' : 'steps' }}
                                                                @if ($group->prompts->isNotEmpty())
                                                                    · {{ \Illuminate\Support\Str::limit($group->prompts->first()->prompt, 72) }}
                                                                @endif
                                                            </span>
                                                        </span>
                                                    </label>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @error('bulkAiPromptGroupId')
                                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror
                                    <p class="mt-3 text-xs text-[#8C8474]">
                                        {{ count($selected) }} selected product{{ count($selected) === 1 ? '' : 's' }}.
                                        Uses each product’s primary photo as the source.
                                    </p>
                                </div>
                            @else
                                <ul class="space-y-2">
                                    @foreach ($bulkAiRows as $row)
                                        <li wire:key="bulk-ai-{{ $row['id'] }}"
                                            class="flex items-start gap-3 rounded-lg border border-[#EFE7D6] px-3 py-2.5">
                                            @if ($row['thumb'])
                                                <img src="{{ \App\Support\StorefrontAssets::url($row['thumb']) }}"
                                                    alt=""
                                                    class="h-12 w-12 shrink-0 rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                            @else
                                                <div class="h-12 w-12 shrink-0 rounded border border-[#E7DFCF] bg-[#FAF6EF]"></div>
                                            @endif
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-[#1E1E1E]" title="{{ $row['name'] }}">{{ $row['name'] }}</p>
                                                @if ($row['message'])
                                                    <p @class([
                                                        'mt-0.5 text-xs',
                                                        'text-rose-600' => $row['status'] === 'failed',
                                                        'text-[#6B6459]' => $row['status'] !== 'failed',
                                                    ])>{{ $row['message'] }}</p>
                                                @endif
                                            </div>
                                            <span @class([
                                                'shrink-0 text-[11px] font-semibold uppercase tracking-wide',
                                                'text-[#8C8474]' => $row['status'] === 'pending',
                                                'text-amber-700' => $row['status'] === 'generating',
                                                'text-emerald-700' => $row['status'] === 'success',
                                                'text-rose-600' => $row['status'] === 'failed',
                                            ])>
                                                @switch($row['status'])
                                                    @case('pending') Waiting @break
                                                    @case('generating') Running @break
                                                    @case('success') Done @break
                                                    @case('failed') Failed @break
                                                    @default {{ $row['status'] }}
                                                @endswitch
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                            @unless ($bulkAiRunning)
                                <button type="button" wire:click="closeBulkAiGenerateModal"
                                    class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                                    {{ $bulkAiRows === [] ? 'Cancel' : 'Done' }}
                                </button>
                            @endunless
                            @if ($bulkAiRows === [])
                                <button type="button"
                                    wire:click="startBulkAiGenerate"
                                    wire:loading.attr="disabled"
                                    wire:target="startBulkAiGenerate"
                                    @disabled(! $geminiConfigured || $aiPromptGroups->isEmpty())
                                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                    <span wire:loading.remove wire:target="startBulkAiGenerate">Run on {{ count($selected) }} product{{ count($selected) === 1 ? '' : 's' }}</span>
                                    <span wire:loading wire:target="startBulkAiGenerate">Starting…</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
