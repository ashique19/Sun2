<div x-data="productImageUploader(@js($this->getId()))">
    @assets
        @vite(['resources/js/admin-product-images.js', 'resources/js/admin-rich-text-editor.js'])
    @endassets

    <div @if ($product) x-data="{ filtersOpen: false }" @endif>
        <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
            <div>
                <a href="{{ route('admin.products', ($listFilters ?? \App\Support\AdminProductListFilters::recall())->queryParameters()) }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Products</a>
                <h1 class="font-serif text-3xl font-semibold mt-2 line-clamp-2">{{ $product?->name ?? 'Create Product' }}</h1>
            </div>
            @if ($product)
                <div class="flex flex-wrap items-center gap-3">
                    <x-admin.product-neighbor-nav :product="$product" route-name="admin.products.edit" />
                    <x-admin.product-list-filters-toggle :filters="$listFilters" />
                    @if ($product->is_published)
                        <a href="{{ route('product.show', $product) }}" target="_blank" class="text-sm text-[#C9A227] hover:underline">View on store ↗</a>
                    @endif
                </div>
            @endif
        </div>

        @if ($product)
            <x-admin.product-list-filters
                :categories="$categories"
                :filters="$listFilters"
                collapsible
                icon-toggle
            />
        @endif
    </div>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <form @submit.prevent="submitProduct()" class="space-y-6">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
            <h2 class="font-semibold text-lg">Product details</h2>
            <div class="grid sm:grid-cols-2 gap-4 max-w-4xl">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium mb-1">Name</label>
                    <input type="text" wire:model.live="name" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Slug</label>
                    <input type="text" wire:model.live="slug" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('slug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">SKU</label>
                    <input type="text" wire:model.live="sku" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Category</label>
                    <select wire:model.live="category_id" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        <option value="">— None —</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Display order</label>
                    <input type="number" wire:model.live="display_order" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="price" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('price') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Regular price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="compare_at_price" placeholder="Optional 'was' price"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <p class="mt-1 text-xs text-[#8C8474]">Optional "was" price shown with strikethrough. Must be greater than selling price.</p>
                    @error('compare_at_price') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Main cost / purchase price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="purchase_price"
                        @disabled($hasBomMaterials)
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                    <p class="mt-1 text-xs text-[#8C8474]">
                        @if ($hasBomMaterials)
                            Set by the primary material on the BOM below.
                        @else
                            Supplier buy price or main material. Total unit cost can include packaging heads below.
                        @endif
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Total unit cost (&#2547;)</label>
                    <input type="text" value="{{ $unit_cost_display }}" readonly
                        class="w-full rounded-lg border border-[#E0D6C2] bg-[#FAF6EF] px-4 py-2 text-sm text-[#6B6459]">
                    <p class="mt-1 text-xs text-[#8C8474]">Used for COGS on orders. Main + other materials + cost heads.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Reseller commission (&#2547; / unit)</label>
                    <input type="number" min="0" step="1" wire:model.live="commission" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <p class="mt-1 text-xs text-[#8C8474]">Base commission at catalog price. Reseller markup above price is added on top.</p>
                    @error('commission') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Max coupon discount (&#2547; / unit)</label>
                    <input type="number" min="0" step="1" wire:model.live="max_discount" placeholder="Uncapped"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <p class="mt-1 text-xs text-[#8C8474]">Caps stacked coupon discounts per unit. Leave blank for no cap.</p>
                    @error('max_discount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Stock quantity</label>
                    <input type="number" wire:model.live="stock_quantity" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                </div>
                <div class="sm:col-span-2 space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-[#1E1E1E]">Descriptions</h3>
                            <p class="mt-0.5 text-xs text-[#8C8474]">
                                Storefront prefers Bangla when present, rendered as formatted HTML.
                            </p>
                        </div>
                        <button type="button"
                            wire:click="generateDescriptionsFromImage"
                            wire:loading.attr="disabled"
                            wire:target="generateDescriptionsFromImage"
                            @disabled(! $geminiConfigured)
                            class="rounded-lg border border-[#1F4E79] bg-white px-3 py-1.5 text-sm font-medium text-[#1F4E79] hover:bg-[#FAF6EF] disabled:cursor-not-allowed disabled:opacity-50"
                            title="{{ $geminiConfigured ? 'Send the primary product image to Gemini' : 'Set GEMINI_API_KEY to enable' }}">
                            <span wire:loading.remove wire:target="generateDescriptionsFromImage">
                                Generate EN + BN from image
                            </span>
                            <span wire:loading wire:target="generateDescriptionsFromImage">
                                Generating…
                            </span>
                        </button>
                    </div>

                    @if ($aiDescriptionError)
                        <p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700" data-ai-description-error>
                            {{ $aiDescriptionError }}
                        </p>
                    @endif
                    @if ($aiDescriptionMessage)
                        <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800" data-ai-description-message>
                            {{ $aiDescriptionMessage }}
                        </p>
                    @endif
                    @unless ($geminiConfigured)
                        <p class="text-xs text-[#8C8474]">Gemini is not configured (<code class="text-[11px]">GEMINI_API_KEY</code>).</p>
                    @endunless

                    <x-admin.rich-text-editor label="English description" property="description" />
                    <x-admin.rich-text-editor label="Bangla description" property="description_bn" />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="is_published" class="rounded border-[#E0D6C2] text-[#C9A227]">
                    Published on storefront
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="is_featured" class="rounded border-[#E0D6C2] text-[#C9A227]">
                    Featured
                </label>
            </div>
        </div>

        <section class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-5">
            <div>
                <h2 class="font-semibold text-lg">Cost breakdown (BOM)</h2>
                <p class="mt-1 text-xs text-[#8C8474]">
                    Link materials and other per-piece heads. Totals save into main cost + unit cost (used for COGS).
                    Manage the catalog under <a href="{{ route('admin.materials') }}" wire:navigate class="text-[#C9A227] hover:underline">Materials</a>.
                </p>
            </div>

            @if ($product)
                <div class="overflow-hidden rounded-lg border border-[#EFE7D6]">
                    <table class="w-full text-sm">
                        <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                            <tr>
                                <th class="px-3 py-2 font-medium">Material</th>
                                <th class="px-3 py-2 font-medium">Qty / piece</th>
                                <th class="px-3 py-2 font-medium">Line cost</th>
                                <th class="px-3 py-2 font-medium">Primary</th>
                                <th class="px-3 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E7DFCF]">
                            @forelse ($product->materials as $material)
                                @php
                                    $lineCost = round((float) $material->pivot->quantity * (float) $material->unit_cost, 2);
                                @endphp
                                <tr wire:key="bom-material-{{ $material->id }}">
                                    <td class="px-3 py-2">
                                        <div class="font-medium">{{ $material->name }}</div>
                                        <div class="text-xs text-[#8C8474]">৳{{ number_format((float) $material->unit_cost, 2) }} / {{ $material->unit }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0.001" step="0.001" value="{{ $material->pivot->quantity }}"
                                            wire:change="updateBomQuantity({{ $material->id }}, $event.target.value)"
                                            class="w-24 rounded-lg border border-[#E0D6C2] px-2 py-1 text-sm">
                                    </td>
                                    <td class="px-3 py-2 tabular-nums">৳{{ number_format($lineCost, 2) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($material->pivot->is_primary)
                                            <span class="text-xs font-semibold text-emerald-700">Main</span>
                                        @else
                                            <button type="button" wire:click="setBomPrimary({{ $material->id }})"
                                                class="text-xs text-[#C9A227] hover:underline">Make main</button>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button type="button" wire:click="removeBomMaterial({{ $material->id }})"
                                            class="text-xs text-rose-600 hover:underline">Remove</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-4 text-center text-[#8C8474]">No materials linked yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-[#6B6459]">Add material</label>
                        <select wire:model="bomMaterialId" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                            <option value="">Select…</option>
                            @foreach ($materialsForBom as $materialOption)
                                <option value="{{ $materialOption->id }}">{{ $materialOption->name }} (৳{{ number_format((float) $materialOption->unit_cost, 2) }})</option>
                            @endforeach
                        </select>
                        @error('bomMaterialId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-[#6B6459]">Qty / piece</label>
                        <input type="number" min="0.001" step="0.001" wire:model="bomQuantity" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                        @error('bomQuantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col justify-end gap-2">
                        <label class="inline-flex items-center gap-2 text-xs">
                            <input type="checkbox" wire:model="bomIsPrimary" class="rounded border-[#E0D6C2] text-[#C9A227]">
                            Primary (main cost)
                        </label>
                        <button type="button" wire:click="addBomMaterial"
                            class="rounded-full border border-[#C9A227] px-4 py-2 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                            Add
                        </button>
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold">Other cost heads / piece</h3>
                    <ul class="mb-3 space-y-1 text-sm">
                        @forelse ($product->costHeads as $head)
                            <li wire:key="cost-head-{{ $head->id }}" class="flex items-center justify-between rounded-lg border border-[#EFE7D6] px-3 py-2">
                                <span>{{ $head->name }} — ৳{{ number_format((float) $head->amount, 2) }}</span>
                                <button type="button" wire:click="removeCostHead({{ $head->id }})" class="text-xs text-rose-600 hover:underline">Remove</button>
                            </li>
                        @empty
                            <li class="text-xs text-[#8C8474]">e.g. packaging labour, electricity share</li>
                        @endforelse
                    </ul>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <input type="text" wire:model="costHeadName" placeholder="Head name" class="rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm sm:col-span-1">
                        <input type="number" min="0" step="0.01" wire:model="costHeadAmount" placeholder="Amount" class="rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm">
                        <button type="button" wire:click="addCostHead"
                            class="rounded-full border border-[#C9A227] px-4 py-2 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                            Add head
                        </button>
                    </div>
                    @error('costHeadName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('costHeadAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            @else
                <p class="text-sm text-[#8C8474]">Save the product first, then link materials and cost heads.</p>
            @endif
        </section>

        <section class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-lg">Product images</h2>
                    <p class="text-xs text-[#8C8474] mt-1">
                        Choose images below, then click <strong>{{ $product ? 'Save Product' : 'Create Product' }}</strong> at the bottom to save them with the product.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="openAiGenerateModal"
                        class="rounded-full border border-[#C9A227] px-4 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                        Generate with AI
                    </button>
                    <p class="text-xs text-[#8C8474]">{{ $product?->images->count() ?? 0 }} saved · <span x-text="queue.length"></span> pending</p>
                </div>
            </div>

            @if ($product?->images->isNotEmpty())
                <div>
                    <h3 class="text-sm font-medium mb-3">Saved images</h3>
                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($product->images as $image)
                            @php
                                $galleryPreviewUrl = route('admin.products.images.raw', [$product, $image])
                                    .'?v='.rawurlencode(md5((string) $image->path).'-'.$imagesEpoch);
                            @endphp
                            <li wire:key="product-image-{{ $image->id }}-{{ md5((string) $image->path) }}-{{ $imagesEpoch }}" class="rounded-xl border border-[#EFE7D6] p-3 space-y-3">
                                <div class="relative aspect-square rounded-lg overflow-hidden bg-[#FAF6EF]">
                                    <img src="{{ $galleryPreviewUrl }}" alt="{{ $image->alt }}" class="w-full h-full object-cover" loading="eager">
                                    @if ($image->is_primary)
                                        <span class="absolute top-2 left-2 rounded bg-[#C9A227] px-2 py-0.5 text-[10px] font-semibold text-white">Primary</span>
                                    @endif
                                    <button type="button"
                                        @click.stop="openSavedEditor({{ $image->id }}, @js($galleryPreviewUrl))"
                                        class="absolute top-2 right-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-[#1E1E1E] shadow-sm ring-1 ring-[#E0D6C2] hover:bg-[#FAF6EF]"
                                        title="Edit image"
                                        aria-label="Edit image">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                            <path d="M2.695 14.763l-1.262 3.154a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.885L17.5 5.5a2.121 2.121 0 00-3-3L3.58 13.42a4 4 0 00-.885 1.343z" />
                                        </svg>
                                    </button>
                                </div>
                                @php($imageMeta = $image->fileMeta())
                                @if ($imageMeta && $imageMeta['label'] !== '')
                                    <p class="text-[11px] tabular-nums text-[#8C8474]" title="Image dimensions and file size">{{ $imageMeta['label'] }}</p>
                                @endif
                                <div>
                                    <label class="block text-xs font-medium text-[#6B6459] mb-1">Alt text</label>
                                    <input type="text"
                                        wire:model.blur="imageAlts.{{ $image->id }}"
                                        wire:change="persistImageAlt({{ $image->id }})"
                                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-xs">
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @unless ($image->is_primary)
                                        <button type="button" wire:click="setPrimaryImage({{ $image->id }})"
                                            class="rounded border border-[#E0D6C2] px-2 py-1 text-xs hover:bg-[#FAF6EF]">
                                            Set primary
                                        </button>
                                    @endunless
                                    <button type="button" wire:click="moveImageEarlier({{ $image->id }})"
                                        class="rounded border border-[#E0D6C2] px-2 py-1 text-xs hover:bg-[#FAF6EF]">↑</button>
                                    <button type="button" wire:click="moveImageLater({{ $image->id }})"
                                        class="rounded border border-[#E0D6C2] px-2 py-1 text-xs hover:bg-[#FAF6EF]">↓</button>
                                    <button type="button" wire:click="deleteImage({{ $image->id }})"
                                        wire:confirm="Remove this image?"
                                        class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">
                                        Delete
                                    </button>
                                </div>
                                <div class="border-t border-[#EFE7D6] pt-3 space-y-2">
                                    <p class="text-[11px] font-medium text-[#6B6459]">Resize (max size, keeps aspect)</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] text-[#8C8474] mb-0.5">Max width</label>
                                            <input type="number" min="1" max="4000"
                                                wire:model="resizeMaxWidths.{{ $image->id }}"
                                                class="w-full rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-xs tabular-nums">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-[#8C8474] mb-0.5">Max height</label>
                                            <input type="number" min="1" max="4000"
                                                wire:model="resizeMaxHeights.{{ $image->id }}"
                                                class="w-full rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-xs tabular-nums">
                                        </div>
                                    </div>
                                    @error('resizeMaxWidths.'.$image->id)
                                        <p class="text-[11px] text-rose-600">{{ $message }}</p>
                                    @enderror
                                    @error('resizeMaxHeights.'.$image->id)
                                        <p class="text-[11px] text-rose-600">{{ $message }}</p>
                                    @enderror
                                    <button type="button"
                                        wire:click="resizeImage({{ $image->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="resizeImage({{ $image->id }})"
                                        class="rounded border border-[#1E1E1E] px-2 py-1 text-xs font-medium text-[#1E1E1E] hover:bg-[#FAF6EF] disabled:opacity-60">
                                        <span wire:loading.remove wire:target="resizeImage({{ $image->id }})">Resize</span>
                                        <span wire:loading wire:target="resizeImage({{ $image->id }})">Resizing…</span>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="border-t border-[#EFE7D6] pt-6 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium">Priced image</h3>
                        <p class="mt-1 text-xs text-[#8C8474]">Shareable photo with price stamped on it. Gallery images stay clean.</p>
                    </div>
                    <button type="button" wire:click="openPricedImageModal"
                        class="rounded-full border border-[#1E1E1E] px-4 py-2 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                        {{ $product?->priced_image_path ? 'Edit priced image' : 'Put price on image' }}
                    </button>
                </div>
                @if ($product?->priced_image_path)
                    <div class="max-w-sm space-y-2">
                        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-[#FAF6EF]">
                            <img src="{{ \App\Support\StorefrontAssets::url($product->priced_image_path) }}"
                                alt="Priced image for {{ $product->name }}"
                                class="w-full object-contain">
                        </div>
                        @php($pricedMeta = \App\Support\ImageFileMeta::forPublicPath($product->priced_image_path))
                        @if ($pricedMeta && $pricedMeta['label'] !== '')
                            <p class="text-[11px] tabular-nums text-[#8C8474]" title="Priced image dimensions and file size">{{ $pricedMeta['label'] }}</p>
                        @endif
                    </div>
                @else
                    <p class="rounded-lg bg-[#FAF6EF] px-4 py-6 text-center text-sm text-[#8C8474]">
                        No priced image yet. Use Put price on image to create one.
                    </p>
                @endif
            </div>

            <div class="border-t border-[#EFE7D6] pt-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium">Add images</h3>
                        <p class="text-xs text-[#8C8474] mt-1">JPG, PNG, or WebP. Large photos are resized in your browser before upload (max 1600px).</p>
                    </div>
                    <label class="cursor-pointer rounded-full bg-[#FAF6EF] px-4 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#EFE7D6]">
                        Choose files
                        <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" multiple @change="addFiles($event)">
                    </label>
                </div>

                @error('newImages') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('newImages.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                <template x-if="queue.length > 0">
                    <div class="space-y-4">
                        <h4 class="text-sm font-medium">Review before upload</h4>
                        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <template x-for="(item, index) in queue" :key="item.id">
                                <li class="rounded-xl border border-[#EFE7D6] p-3 space-y-3">
                                    <div class="relative aspect-square rounded-lg overflow-hidden bg-[#FAF6EF]">
                                        <img :src="item.previewUrl" :alt="item.name" class="w-full h-full object-cover">
                                        <span x-show="item.edited" class="absolute top-2 right-2 rounded bg-[#1E1E1E] px-2 py-0.5 text-[10px] font-semibold text-white">Edited</span>
                                    </div>
                                    <p class="text-xs text-[#8C8474] truncate" :title="item.name" x-text="item.name"></p>
                                    <p class="text-[11px] tabular-nums text-[#8C8474]" x-show="item.metaLabel" x-text="item.metaLabel" title="Image dimensions and file size"></p>
                                    <div>
                                        <label class="block text-xs font-medium text-[#6B6459] mb-1">Alt text</label>
                                        <input type="text" x-model="item.alt" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-xs" placeholder="Optional description">
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button" @click.stop="openEditor(index)"
                                            class="rounded border border-[#C9A227] px-2 py-1 text-xs text-[#C9A227] hover:bg-[#FAF6EF]">
                                            Edit
                                        </button>
                                        <button type="button" @click.stop="removeFromQueue(index)"
                                            class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">
                                            Remove
                                        </button>
                                    </div>
                                </li>
                            </template>
                        </ul>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button"
                                @click="uploadAll()"
                                :disabled="uploading"
                                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                <span x-show="!uploading" x-text="`Upload ${queue.length} image(s)`"></span>
                                <span x-show="uploading" x-cloak>Uploading…</span>
                            </button>
                            <p class="text-xs text-[#8C8474]" x-show="!uploading" x-text="`${queue.length} image(s) ready — or use Save Product below`"></p>
                        </div>
                        <div x-show="uploading" x-cloak class="max-w-md space-y-1.5">
                            <div class="flex items-center justify-between gap-2 text-xs text-[#8C8474]">
                                <span x-text="uploadStatus || 'Uploading…'"></span>
                                <span class="tabular-nums" x-text="`${uploadProgress}%`"></span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                                :aria-valuenow="uploadProgress" aria-valuemin="0" aria-valuemax="100"
                                :aria-label="uploadStatus || 'Upload progress'">
                                <div class="h-full rounded-full bg-[#C9A227] transition-[width] duration-150"
                                    :style="`width: ${uploadProgress}%`"></div>
                            </div>
                        </div>
                        <p x-show="uploadError" x-text="uploadError" class="text-xs text-rose-600" x-cloak></p>
                    </div>
                </template>

                <template x-if="queue.length === 0 && {{ ($product?->images->isEmpty() ?? true) ? 'true' : 'false' }}">
                    <p class="text-sm text-[#8C8474] rounded-lg bg-[#FAF6EF] px-4 py-8 text-center">No images yet. Choose files to get started.</p>
                </template>
            </div>

            {{-- Keep Alpine modal out of Livewire morphs: x-show styles were stripped on re-render, leaving the dialog stuck open. --}}
            <div wire:ignore>
                <template x-teleport="body">
                    <template x-if="editorOpen">
                        <div
                            class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4"
                            @keydown.escape.window="closeEditor()"
                            @click.self="onEditorOutside()"
                            role="dialog"
                            aria-modal="true"
                            aria-label="Edit image">
                            <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl overflow-hidden" @click.stop>
                                <div class="flex items-center justify-between border-b border-[#EFE7D6] px-4 py-3">
                                    <h3 class="font-semibold">Edit image</h3>
                                    <button type="button" @click="closeEditor()" class="text-sm text-[#6B6459] hover:text-[#1E1E1E]">Close</button>
                                </div>
                                <div class="max-h-[60vh] bg-[#FAF6EF]">
                                    <img x-ref="cropImage" :src="queue[editorIndex]?.previewUrl" alt="" class="block max-w-full max-h-[60vh] mx-auto">
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#EFE7D6] px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" @click="rotate(-90)" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Rotate left</button>
                                        <button type="button" @click="rotate(90)" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Rotate right</button>
                                        <button type="button" @click="resetCrop()" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Reset</button>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" @click="closeEditor()" class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm">Cancel</button>
                                        <button type="button" @click="applyCrop()" class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>

                <template x-teleport="body">
                    <template x-if="savedEditorOpen">
                        <div
                            class="fixed inset-0 z-[85] flex items-center justify-center bg-black/60 p-4"
                            @keydown.escape.window="if (! savedSaving) closeSavedEditor()"
                            @click.self="onSavedEditorOutside()"
                            role="dialog"
                            aria-modal="true"
                            aria-label="Edit saved image">
                            <div class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" @click.stop>
                                <div class="flex items-center justify-between border-b border-[#EFE7D6] px-4 py-3">
                                    <div>
                                        <h3 class="font-semibold">Edit image</h3>
                                        <p class="text-xs text-[#8C8474]">Drag the crop box, then check the live preview with text/logo.</p>
                                    </div>
                                    <button type="button" @click="closeSavedEditor()" :disabled="savedSaving" class="text-sm text-[#6B6459] hover:text-[#1E1E1E] disabled:opacity-60">Close</button>
                                </div>

                                <div class="min-h-0 flex-1 overflow-y-auto">
                                    <div class="grid gap-0 lg:grid-cols-2">
                                        <div class="border-b border-[#EFE7D6] lg:border-b-0 lg:border-r">
                                            <div class="flex items-center justify-between gap-2 border-b border-[#EFE7D6] bg-[#FAF6EF] px-4 py-2">
                                                <p class="text-xs font-medium text-[#6B6459]">Crop</p>
                                                <p class="text-[11px] text-[#8C8474]">Drag corners to crop · scroll to zoom</p>
                                            </div>
                                            <div class="saved-cropper-wrap relative min-h-[280px] bg-[#2A2A2A]" data-saved-editor>
                                                <img data-saved-crop-image :src="savedEditorSrc" alt=""
                                                    class="block max-h-[46vh] max-w-full mx-auto"
                                                    crossorigin="anonymous">
                                            </div>
                                            <div class="space-y-3 border-t border-[#EFE7D6] px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" @click="rotateSaved(-90)" :disabled="savedSaving" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF] disabled:opacity-60">Rotate left</button>
                                                    <button type="button" @click="rotateSaved(90)" :disabled="savedSaving" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF] disabled:opacity-60">Rotate right</button>
                                                    <button type="button" @click="resetSavedCrop()" :disabled="savedSaving" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF] disabled:opacity-60">Reset crop</button>
                                                </div>
                                                <div>
                                                    <p class="mb-1.5 text-[11px] text-[#8C8474]">Aspect ratio</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button type="button" @click="setSavedAspect('free')" :disabled="savedSaving"
                                                            class="rounded border px-2.5 py-1 text-xs disabled:opacity-60"
                                                            :class="savedAspect === 'free' ? 'border-[#C9A227] bg-[#FAF6EF] text-[#C9A227]' : 'border-[#E0D6C2] hover:bg-[#FAF6EF]'">Free</button>
                                                        <button type="button" @click="setSavedAspect(1)" :disabled="savedSaving"
                                                            class="rounded border px-2.5 py-1 text-xs disabled:opacity-60"
                                                            :class="savedAspect === 1 ? 'border-[#C9A227] bg-[#FAF6EF] text-[#C9A227]' : 'border-[#E0D6C2] hover:bg-[#FAF6EF]'">1:1</button>
                                                        <button type="button" @click="setSavedAspect(4/3)" :disabled="savedSaving"
                                                            class="rounded border px-2.5 py-1 text-xs disabled:opacity-60"
                                                            :class="savedAspect === 4/3 ? 'border-[#C9A227] bg-[#FAF6EF] text-[#C9A227]' : 'border-[#E0D6C2] hover:bg-[#FAF6EF]'">4:3</button>
                                                        <button type="button" @click="setSavedAspect(3/4)" :disabled="savedSaving"
                                                            class="rounded border px-2.5 py-1 text-xs disabled:opacity-60"
                                                            :class="savedAspect === 3/4 ? 'border-[#C9A227] bg-[#FAF6EF] text-[#C9A227]' : 'border-[#E0D6C2] hover:bg-[#FAF6EF]'">3:4</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="flex items-center justify-between gap-2 border-b border-[#EFE7D6] bg-[#FAF6EF] px-4 py-2">
                                                <p class="text-xs font-medium text-[#6B6459]">Live preview</p>
                                                <p class="text-[11px] text-[#8C8474]" x-show="savedPreviewPending" x-cloak>Updating…</p>
                                            </div>
                                            <div class="flex min-h-[280px] items-center justify-center bg-[#FAF6EF] px-4 py-4">
                                                <template x-if="savedPreviewUrl">
                                                    <img :src="savedPreviewUrl" alt="Edited image preview"
                                                        class="max-h-[46vh] max-w-full rounded-lg border border-[#E0D6C2] object-contain shadow-sm">
                                                </template>
                                                <p x-show="! savedPreviewUrl" class="text-sm text-[#8C8474]" x-cloak>Preview will appear here.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-5 border-t border-[#EFE7D6] px-4 py-4">
                                        <div class="space-y-3 rounded-xl border border-[#EFE7D6] bg-[#FAF6EF]/px-4 py-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <p class="text-xs font-medium text-[#6B6459]">Adjust tone</p>
                                                <button type="button" @click="resetToneAdjustments()" :disabled="savedSaving || (editBrightness === 0 && editRedTone === 0)"
                                                    class="text-[11px] text-[#C9A227] hover:underline disabled:opacity-40 disabled:no-underline">
                                                    Reset
                                                </button>
                                            </div>
                                            <div>
                                                <label class="mb-1 flex items-center justify-between gap-2 text-[11px] text-[#8C8474]" for="saved-edit-brightness">
                                                    <span>Brightness</span>
                                                    <span class="tabular-nums text-[#6B6459]" x-text="editBrightness === 0 ? '0' : (editBrightness > 0 ? `+${editBrightness}` : `${editBrightness}`)"></span>
                                                </label>
                                                <input id="saved-edit-brightness" type="range" min="-40" max="40" step="1" x-model.number="editBrightness" :disabled="savedSaving"
                                                    class="w-full accent-[#C9A227] disabled:opacity-60">
                                            </div>
                                            <div>
                                                <label class="mb-1 flex items-center justify-between gap-2 text-[11px] text-[#8C8474]" for="saved-edit-red-tone">
                                                    <span>Red tone</span>
                                                    <span class="tabular-nums text-[#6B6459]" x-text="editRedTone === 0 ? 'Neutral' : (editRedTone > 0 ? `Warm +${editRedTone}` : `Cool ${editRedTone}`)"></span>
                                                </label>
                                                <input id="saved-edit-red-tone" type="range" min="-40" max="40" step="1" x-model.number="editRedTone" :disabled="savedSaving"
                                                    class="w-full accent-[#C9A227] disabled:opacity-60">
                                                <p class="mt-1 text-[10px] text-[#8C8474]">Positive warms toward red · negative cools.</p>
                                            </div>
                                        </div>

                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div class="space-y-3">
                                                <p class="text-xs font-medium text-[#6B6459]">Put text on image</p>
                                                <div>
                                                    <label class="mb-1 block text-[11px] text-[#8C8474]" for="saved-overlay-text">Text</label>
                                                    <input id="saved-overlay-text" type="text" x-model="overlayText" :disabled="savedSaving"
                                                        placeholder="Optional overlay text"
                                                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm disabled:opacity-60">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] text-[#8C8474]" for="saved-overlay-text-size">
                                                        Text size <span class="tabular-nums" x-text="`${overlayTextSize}px`"></span>
                                                    </label>
                                                    <input id="saved-overlay-text-size" type="range" min="20" max="96" step="1" x-model.number="overlayTextSize" :disabled="savedSaving"
                                                        class="w-full accent-[#C9A227] disabled:opacity-60">
                                                </div>
                                                <div>
                                                    <div class="flex gap-1.5" role="group" aria-label="Text position">
                                                        <template x-for="option in overlayPositions()" :key="`text-${option.value}`">
                                                            <button type="button"
                                                                @click="overlayTextPosition = option.value"
                                                                :title="option.label"
                                                                :aria-label="option.label"
                                                                :aria-pressed="overlayTextPosition === option.value ? 'true' : 'false'"
                                                                :disabled="savedSaving"
                                                                :class="overlayTextPosition === option.value
                                                                    ? 'border-[#1E1E1E] bg-[#1E1E1E] text-white'
                                                                    : 'border-[#E0D6C2] bg-white text-[#1E1E1E] hover:bg-[#FAF6EF]'"
                                                                class="inline-flex h-9 flex-1 items-center justify-center rounded-lg border transition disabled:opacity-60">
                                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                    <rect x="2.5" y="2.5" width="15" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.45"/>
                                                                    <rect :x="option.icon.x" :y="option.icon.y" :width="option.icon.w" :height="option.icon.h" rx="0.75"/>
                                                                </svg>
                                                                <span class="sr-only" x-text="option.label"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="space-y-3">
                                                <div class="flex items-center justify-between gap-2">
                                                    <p class="text-xs font-medium text-[#6B6459]">Put logo on image</p>
                                                    <label class="flex items-center gap-2 text-xs text-[#6B6459]">
                                                        <input type="checkbox" x-model="overlayLogoEnabled" :disabled="savedSaving" class="rounded border-[#E0D6C2] text-[#C9A227]">
                                                        Enable
                                                    </label>
                                                </div>
                                                <div class="overflow-hidden rounded-lg border border-[#EFE7D6] bg-[#FAF6EF] px-3 py-2">
                                                    <img :src="logoUrl" alt="Brand logo" class="mx-auto h-8 w-auto object-contain opacity-90">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] text-[#8C8474]" for="saved-overlay-logo-size">
                                                        Logo size <span class="tabular-nums" x-text="`${overlayLogoSize}%`"></span>
                                                    </label>
                                                    <input id="saved-overlay-logo-size" type="range" min="8" max="50" step="1" x-model.number="overlayLogoSize"
                                                        :disabled="savedSaving || ! overlayLogoEnabled"
                                                        class="w-full accent-[#C9A227] disabled:opacity-60">
                                                </div>
                                                <div>
                                                    <div class="flex gap-1.5" role="group" aria-label="Logo position"
                                                        :class="{ 'opacity-60': ! overlayLogoEnabled }">
                                                        <template x-for="option in overlayPositions()" :key="`logo-${option.value}`">
                                                            <button type="button"
                                                                @click="overlayLogoPosition = option.value"
                                                                :title="option.label"
                                                                :aria-label="`Logo ${option.label}`"
                                                                :aria-pressed="overlayLogoPosition === option.value ? 'true' : 'false'"
                                                                :disabled="savedSaving || ! overlayLogoEnabled"
                                                                :class="overlayLogoPosition === option.value
                                                                    ? 'border-[#1E1E1E] bg-[#1E1E1E] text-white'
                                                                    : 'border-[#E0D6C2] bg-white text-[#1E1E1E] hover:bg-[#FAF6EF]'"
                                                                class="inline-flex h-9 flex-1 items-center justify-center rounded-lg border transition disabled:opacity-60">
                                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                    <rect x="2.5" y="2.5" width="15" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.45"/>
                                                                    <rect :x="option.icon.x" :y="option.icon.y" :width="option.icon.w" :height="option.icon.h" rx="0.75"/>
                                                                </svg>
                                                                <span class="sr-only" x-text="`Logo ${option.label}`"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <p x-show="savedError" x-text="savedError" class="text-xs text-rose-600" x-cloak></p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                                    <button type="button" @click="closeSavedEditor()" :disabled="savedSaving"
                                        class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm disabled:opacity-60">Cancel</button>
                                    <button type="button" @click="saveSavedEdit()" :disabled="savedSaving"
                                        class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                        <span x-show="! savedSaving">Save changes</span>
                                        <span x-show="savedSaving" x-cloak>Saving…</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </div>
        </section>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]" :disabled="uploading">
                <span x-show="!uploading">{{ $product ? 'Save Product' : 'Create Product' }}</span>
                <span x-show="uploading" x-cloak>Saving…</span>
            </button>
            @if ($product)
                <button type="button"
                    wire:click="delete"
                    wire:confirm="Delete this product? Order history will keep line snapshots, but the product and its images will be removed."
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50"
                    :disabled="uploading">
                    Delete
                </button>
            @endif
            <p x-show="uploadError" x-text="uploadError" class="text-xs text-rose-600" x-cloak></p>
        </div>
    </form>

    {{-- Keep AI modal in the Livewire/Alpine tree (not body-teleported) so file uploads can finish. --}}
    <div
        wire:key="ai-generate-modal-host"
        x-data="aiImageCandidates()"
        x-init="geminiConfigured = {{ $geminiConfigured ? 'true' : 'false' }}"
        x-effect="if (! $wire.showAiGenerateModal) { clearRawImage(); clearGenerateState(); }">
        @if ($showAiGenerateModal)
            <div class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-4 sm:items-center"
                wire:click.self="closeAiGenerateModal"
                wire:key="ai-generate-modal"
                role="dialog"
                aria-modal="true"
                aria-label="Generate product images with AI">
                <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl"
                    wire:click.stop>
                    <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                        <div>
                            <h2 class="font-semibold text-lg">Generate with AI</h2>
                            <p class="mt-1 text-xs text-[#8C8474]">
                                Choose a raw photo, write a prompt, then Generate. Candidates stay for this session only until you add them with +.
                            </p>
                        </div>
                        <button type="button" wire:click="closeAiGenerateModal" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                    </div>

                    <div class="flex-1 space-y-5 overflow-y-auto px-4 py-4">
                        @unless ($geminiConfigured)
                            <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                Gemini is not configured. Set <code class="font-mono text-xs">GEMINI_API_KEY</code>
                                (and optional <code class="font-mono text-xs">GEMINI_API_KEYS</code>) to enable generation.
                            </div>
                        @endunless

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium mb-1">Raw photo</label>
                                <input type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    @change="uploadRawPhoto($event)"
                                    class="block w-full text-sm text-[#6B6459] file:mr-3 file:rounded-full file:border-0 file:bg-[#FAF6EF] file:px-4 file:py-2 file:text-sm file:font-medium file:text-[#C9A227] hover:file:bg-[#EFE7D6] disabled:opacity-60"
                                    :disabled="rawUploading">
                                <div x-show="rawUploading" x-cloak class="mt-2 space-y-1">
                                    <div class="flex items-center justify-between gap-2 text-xs text-[#8C8474]">
                                        <span>Preparing raw photo…</span>
                                        <span class="tabular-nums" x-text="`${rawUploadProgress}%`"></span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                                        :aria-valuenow="rawUploadProgress" aria-valuemin="0" aria-valuemax="100">
                                        <div class="h-full rounded-full bg-[#C9A227] transition-[width] duration-150"
                                            :style="`width: ${rawUploadProgress}%`"></div>
                                    </div>
                                </div>
                                <p x-show="! rawUploading && hasRawImage" x-cloak class="mt-2 text-xs text-[#8C8474]">
                                    Raw photo ready<span x-show="rawImageName" x-text="`: ${rawImageName}`"></span>.
                                </p>
                                <p x-show="rawUploadError" x-text="rawUploadError" x-cloak class="mt-1 text-xs text-rose-600"></p>
                                @error('aiRawImage') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Prompt</label>
                                <textarea wire:model="aiPrompt" rows="4"
                                    placeholder="Describe how to improve or restyle the product photo…"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm"></textarea>
                                @error('aiPrompt') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($recentAiPrompts->isNotEmpty())
                            <div>
                                <p class="text-xs font-medium text-[#6B6459] mb-2">Recent prompts</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($recentAiPrompts as $recent)
                                        <button type="button"
                                            wire:click="useRecentPrompt(@js($recent->prompt))"
                                            class="max-w-full truncate rounded-full border border-[#E0D6C2] bg-[#FAF6EF] px-3 py-1 text-xs text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]"
                                            title="{{ $recent->prompt }}">
                                            {{ \Illuminate\Support\Str::limit($recent->prompt, 48) }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button"
                                    @click="generateWithRaw()"
                                    :disabled="! canGenerate()"
                                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                    <span x-text="generating ? 'Generating…' : 'Generate'"></span>
                                </button>
                                <p class="text-xs text-[#8C8474]">Each Generate adds another candidate to this session list.</p>
                            </div>

                            <div x-show="generating || generateProgress > 0 || generateError" x-cloak class="max-w-md space-y-1">
                                <div class="flex items-center justify-between gap-2 text-xs"
                                    :class="generateError ? 'text-rose-600' : 'text-[#8C8474]'">
                                    <span x-text="generateStatus || 'Working…'"></span>
                                    <span class="tabular-nums" x-text="`${generateProgress}%`"></span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                                    :aria-valuenow="generateProgress" aria-valuemin="0" aria-valuemax="100"
                                    aria-label="AI generation progress">
                                    <div class="h-full rounded-full transition-[width] duration-150"
                                        :class="generateError ? 'bg-rose-400' : 'bg-[#C9A227]'"
                                        :style="`width: ${generateProgress}%`"></div>
                                </div>
                            </div>

                            <p x-show="generateError" x-text="generateError" x-cloak class="text-sm text-rose-600"></p>
                        </div>

                            @if (count($aiCandidates) > 0)
                                <div>
                                    <h3 class="text-sm font-medium mb-3">Generated this session ({{ count($aiCandidates) }})</h3>
                                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($aiCandidates as $candidate)
                                            <li wire:key="ai-candidate-{{ $candidate['id'] }}" class="rounded-xl border border-[#EFE7D6] p-3 space-y-3">
                                                <div class="relative aspect-square overflow-hidden rounded-lg bg-[#FAF6EF]">
                                                    <img src="data:{{ $candidate['mime'] }};base64,{{ $candidate['base64'] }}"
                                                        alt="{{ $candidate['name'] }}"
                                                        class="h-full w-full object-cover">
                                                </div>
                                                <div class="flex flex-wrap gap-1">
                                                    <button type="button"
                                                        @click="openAiEditor(@js($candidate['id']))"
                                                        class="rounded border border-[#C9A227] px-2 py-1 text-xs text-[#C9A227] hover:bg-[#FAF6EF]">
                                                        Edit
                                                    </button>
                                                    <button type="button"
                                                        wire:click="promoteAiCandidate(@js($candidate['id']))"
                                                        wire:loading.attr="disabled"
                                                        class="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                                                        title="Add to product images">
                                                        +
                                                    </button>
                                                    <button type="button"
                                                        wire:click="removeAiCandidate(@js($candidate['id']))"
                                                        class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">
                                                        Discard
                                                    </button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <div wire:ignore>
                            <template x-teleport="body">
                                <template x-if="aiEditorOpen">
                                    <div
                                        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4"
                                        @keydown.escape.window="closeAiEditor()"
                                        @click.self="onAiEditorOutside()"
                                        role="dialog"
                                        aria-modal="true"
                                        aria-label="Edit generated image">
                                        <div class="w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-xl" @click.stop>
                                            <div class="flex items-center justify-between border-b border-[#EFE7D6] px-4 py-3">
                                                <h3 class="font-semibold">Edit generated image</h3>
                                                <button type="button" @click="closeAiEditor()" class="text-sm text-[#6B6459] hover:text-[#1E1E1E]">Close</button>
                                            </div>
                                            <div class="max-h-[60vh] bg-[#FAF6EF]">
                                                <img x-ref="aiCropImage" :src="aiEditorSrc" alt="" class="mx-auto block max-h-[60vh] max-w-full">
                                            </div>
                                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#EFE7D6] px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" @click="rotateAi(-90)" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Rotate left</button>
                                                    <button type="button" @click="rotateAi(90)" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Rotate right</button>
                                                    <button type="button" @click="resetAiCrop()" class="rounded border border-[#E0D6C2] px-3 py-1.5 text-xs hover:bg-[#FAF6EF]">Reset</button>
                                                </div>
                                                <div class="flex gap-2">
                                                    <button type="button" @click="closeAiEditor()" class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm">Cancel</button>
                                                    <button type="button" @click="applyAiCrop()" class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">Apply</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
        @endif
    </div>

    @teleport('body')
        <div wire:key="priced-image-modal-host">
            @if ($showPricedImageModal)
                <div class="fixed inset-0 z-[80] flex items-stretch justify-center bg-black/50 sm:items-center sm:p-4"
                    wire:click.self="closePricedImageModal"
                    wire:key="priced-image-modal"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Priced image controls">
                    <div class="flex h-dvh w-full max-w-4xl flex-col overflow-hidden bg-white shadow-xl sm:h-auto sm:max-h-[min(90dvh,42rem)] sm:rounded-xl"
                        wire:click.stop>
                        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                            <h2 class="font-semibold text-lg">Priced image</h2>
                            <button type="button" wire:click="closePricedImageModal"
                                class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                                Close
                            </button>
                        </div>

                        <div class="shrink-0 space-y-3 border-b border-[#EFE7D6] px-4 py-3">
                            <div class="flex gap-1.5" role="group" aria-label="Text position">
                                @foreach ([
                                    'top-left' => 'Top left',
                                    'top-right' => 'Top right',
                                    'bottom-left' => 'Bottom left',
                                    'bottom-right' => 'Bottom right',
                                    'center' => 'Center',
                                ] as $value => $label)
                                    <button type="button"
                                        wire:click="$set('pricedImagePosition', '{{ $value }}')"
                                        title="{{ $label }}"
                                        aria-label="{{ $label }}"
                                        aria-pressed="{{ $pricedImagePosition === $value ? 'true' : 'false' }}"
                                        class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border transition
                                            {{ $pricedImagePosition === $value
                                                ? 'border-[#1E1E1E] bg-[#1E1E1E] text-white'
                                                : 'border-[#E0D6C2] bg-white text-[#1E1E1E] hover:bg-[#FAF6EF]' }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                            <rect x="2.5" y="2.5" width="15" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.45"/>
                                            @switch($value)
                                                @case('top-left')
                                                    <rect x="4" y="4" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('top-right')
                                                    <rect x="10.5" y="4" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('bottom-left')
                                                    <rect x="4" y="12" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('bottom-right')
                                                    <rect x="10.5" y="12" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @default
                                                    <rect x="6.5" y="7.5" width="7" height="5" rx="0.75"/>
                                            @endswitch
                                        </svg>
                                        <span class="sr-only">{{ $label }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="priced-image-font" type="range"
                                    min="{{ \App\Services\Admin\ProductPricedImageService::FONT_MIN }}"
                                    max="{{ \App\Services\Admin\ProductPricedImageService::FONT_MAX }}"
                                    step="4"
                                    wire:model="pricedImageFont"
                                    aria-label="Text size in pixels"
                                    class="min-w-0 flex-1">
                                <input type="number"
                                    min="{{ \App\Services\Admin\ProductPricedImageService::FONT_MIN }}"
                                    max="{{ \App\Services\Admin\ProductPricedImageService::FONT_MAX }}"
                                    wire:model="pricedImageFont"
                                    aria-label="Text size in pixels"
                                    class="w-20 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                            </div>
                            @error('pricedImage')
                                <p class="text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                            @error('pricedImagePosition')
                                <p class="text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                            @error('pricedImageFont')
                                <p class="text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="generatePricedImage"
                                    wire:loading.attr="disabled"
                                    class="rounded-full bg-[#1E1E1E] px-5 py-2 text-sm font-semibold text-white hover:bg-black disabled:opacity-60">
                                    <span wire:loading.remove wire:target="generatePricedImage">
                                        {{ $product?->priced_image_path ? 'Save & rebuild' : 'Save & generate' }}
                                    </span>
                                    <span wire:loading wire:target="generatePricedImage">Saving…</span>
                                </button>
                                @if ($product?->priced_image_path)
                                    <button type="button"
                                        wire:click="deletePricedImage"
                                        wire:confirm="Delete this priced image? Position and size settings are kept for next time."
                                        wire:loading.attr="disabled"
                                        wire:target="deletePricedImage"
                                        class="rounded-full border border-rose-300 px-5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60">
                                        <span wire:loading.remove wire:target="deletePricedImage">Delete</span>
                                        <span wire:loading wire:target="deletePricedImage">Deleting…</span>
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain px-4 py-4">
                            @if ($product?->priced_image_path)
                                <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-[#FAF6EF]">
                                    <img src="{{ \App\Support\StorefrontAssets::url($product->priced_image_path) }}" alt="Priced image preview" class="w-full object-contain">
                                </div>
                            @else
                                <div class="rounded-xl border border-dashed border-[#E0D6C2] bg-[#FAF6EF] px-4 py-12 text-center text-sm text-[#8C8474]">
                                    Generate once to preview the priced image here.
                                </div>
                            @endif
                            <p class="text-xs text-[#8C8474]">Uses the primary product image and current price / regular price. Rebuild after changing photo or price.</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
