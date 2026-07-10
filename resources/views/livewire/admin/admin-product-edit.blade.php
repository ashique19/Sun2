<div x-data="productImageUploader()">
    @vite(['resources/js/admin-product-images.js'])

    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <a href="{{ route('admin.products') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Products</a>
            <h1 class="font-serif text-3xl font-semibold mt-2 line-clamp-2">{{ $product?->name ?? 'Create Product' }}</h1>
        </div>
        @if ($product?->is_published)
            <a href="{{ route('product.show', $product) }}" target="_blank" class="text-sm text-[#C9A227] hover:underline">View on store ↗</a>
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
                    <label class="block text-sm font-medium mb-1">Purchase price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="purchase_price" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Stock quantity</label>
                    <input type="number" wire:model.live="stock_quantity" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium mb-1">Description (HTML allowed)</label>
                    <textarea wire:model.live="description" rows="8" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm font-mono text-xs"></textarea>
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

        <section class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-lg">Product images</h2>
                    <p class="text-xs text-[#8C8474] mt-1">
                        Choose images below, then click <strong>{{ $product ? 'Save Product' : 'Create Product' }}</strong> at the bottom to save them with the product.
                    </p>
                </div>
                <p class="text-xs text-[#8C8474]">{{ $product?->images->count() ?? 0 }} saved · <span x-text="queue.length"></span> pending</p>
            </div>

            @if ($product?->images->isNotEmpty())
                <div>
                    <h3 class="text-sm font-medium mb-3">Saved images</h3>
                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($product->images as $image)
                            <li wire:key="product-image-{{ $image->id }}" class="rounded-xl border border-[#EFE7D6] p-3 space-y-3">
                                <div class="relative aspect-square rounded-lg overflow-hidden bg-[#FAF6EF]">
                                    @if ($url = \App\Support\StorefrontAssets::url($image->path))
                                        <img src="{{ $url }}" alt="{{ $image->alt }}" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-xs text-[#8C8474]">No preview</div>
                                    @endif
                                    @if ($image->is_primary)
                                        <span class="absolute top-2 left-2 rounded bg-[#C9A227] px-2 py-0.5 text-[10px] font-semibold text-white">Primary</span>
                                    @endif
                                </div>
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
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="border-t border-[#EFE7D6] pt-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium">Add images</h3>
                        <p class="text-xs text-[#8C8474] mt-1">JPG, PNG, or WebP up to 5 MB each.</p>
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
                                    <div>
                                        <label class="block text-xs font-medium text-[#6B6459] mb-1">Alt text</label>
                                        <input type="text" x-model="item.alt" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-xs" placeholder="Optional description">
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button" @click="openEditor(index)"
                                            class="rounded border border-[#C9A227] px-2 py-1 text-xs text-[#C9A227] hover:bg-[#FAF6EF]">
                                            Edit
                                        </button>
                                        <button type="button" @click="removeFromQueue(index)"
                                            class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">
                                            Remove
                                        </button>
                                    </div>
                                </li>
                            </template>
                        </ul>
                        <p class="text-xs text-[#8C8474]" x-text="`${queue.length} image(s) ready`"></p>
                    </div>
                </template>

                <template x-if="queue.length === 0 && {{ ($product?->images->isEmpty() ?? true) ? 'true' : 'false' }}">
                    <p class="text-sm text-[#8C8474] rounded-lg bg-[#FAF6EF] px-4 py-8 text-center">No images yet. Choose files to get started.</p>
                </template>
            </div>

            <div x-show="editorOpen" x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                @keydown.escape.window="closeEditor()">
                <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl overflow-hidden" @click.outside="closeEditor()">
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
</div>
