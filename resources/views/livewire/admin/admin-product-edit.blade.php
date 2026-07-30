<div x-data="productImageUploader()">
    @assets
        @vite(['resources/js/admin-product-images.js'])
    @endassets

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
                    <label class="block text-sm font-medium mb-1">Regular price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="compare_at_price" placeholder="Optional 'was' price"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <p class="mt-1 text-xs text-[#8C8474]">Optional "was" price shown with strikethrough. Must be greater than selling price.</p>
                    @error('compare_at_price') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Purchase price (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="purchase_price" class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
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
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="openPricedImageModal"
                        class="rounded-full border border-[#1E1E1E] px-4 py-2 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                        {{ $product?->priced_image_path ? 'Edit priced image' : 'Put price on image' }}
                    </button>
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
                    <div class="max-w-sm overflow-hidden rounded-xl border border-[#EFE7D6] bg-[#FAF6EF]">
                        <img src="{{ \App\Support\StorefrontAssets::url($product->priced_image_path) }}"
                            alt="Priced image for {{ $product->name }}"
                            class="w-full object-contain">
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
                            <p class="text-xs text-[#8C8474]" x-text="`${queue.length} image(s) ready — or use Save Product below`"></p>
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

    @teleport('body')
        <div wire:key="ai-generate-modal-host" x-data="aiImageCandidates()">
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
                                    Upload a raw photo, write a prompt, then Generate. Candidates stay for this session only until you add them with +.
                                </p>
                            </div>
                            <button type="button" wire:click="closeAiGenerateModal" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                        </div>

                        <div class="flex-1 space-y-5 overflow-y-auto px-4 py-4">
                            @unless ($geminiConfigured)
                                <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                    Gemini is not configured. Set <code class="font-mono text-xs">GEMINI_API_KEY</code> to enable generation.
                                </div>
                            @endunless

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium mb-1">Raw photo</label>
                                    <input type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        @change="uploadRawPhoto($event)"
                                        :disabled="rawUploading"
                                        class="block w-full text-sm text-[#6B6459] file:mr-3 file:rounded-full file:border-0 file:bg-[#FAF6EF] file:px-4 file:py-2 file:text-sm file:font-medium file:text-[#C9A227] hover:file:bg-[#EFE7D6] disabled:opacity-60">
                                    <p x-show="rawUploading" x-cloak class="mt-1 text-xs text-[#8C8474]">Uploading raw photo…</p>
                                    <p x-show="rawUploadError" x-text="rawUploadError" x-cloak class="mt-1 text-xs text-rose-600"></p>
                                    @error('aiRawImage') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    @if ($aiRawImage)
                                        <p class="mt-2 text-xs text-[#8C8474]">Selected: {{ method_exists($aiRawImage, 'getClientOriginalName') ? $aiRawImage->getClientOriginalName() : 'raw photo' }}</p>
                                    @endif
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

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button"
                                    wire:click="generateAiImage"
                                    wire:loading.attr="disabled"
                                    wire:target="generateAiImage"
                                    :disabled="rawUploading || {{ json_encode(! $geminiConfigured || ! $aiRawImage) }}"
                                    class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                    <span wire:loading.remove wire:target="generateAiImage">Generate</span>
                                    <span wire:loading wire:target="generateAiImage">Generating…</span>
                                </button>
                                <p class="text-xs text-[#8C8474]">Each Generate adds another candidate to this session list.</p>
                            </div>

                            @if ($aiGenerateError)
                                <p class="text-sm text-rose-600">{{ $aiGenerateError }}</p>
                            @endif

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
    @endteleport

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

                        <div class="shrink-0 space-y-4 border-b border-[#EFE7D6] px-4 py-4">
                            <div>
                                <label class="mb-2 block text-sm font-medium">Text position</label>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach ([
                                        'top-left' => 'Top left',
                                        'top-right' => 'Top right',
                                        'bottom-left' => 'Bottom left',
                                        'bottom-right' => 'Bottom right',
                                    ] as $value => $label)
                                        <button type="button"
                                            wire:click="$set('pricedImagePosition', '{{ $value }}')"
                                            class="rounded-lg border px-3 py-2 text-left text-sm transition
                                                {{ $pricedImagePosition === $value
                                                    ? 'border-[#1E1E1E] bg-[#1E1E1E] text-white'
                                                    : 'border-[#E0D6C2] bg-white text-[#1E1E1E] hover:bg-[#FAF6EF]' }}">
                                            {{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="priced-image-font">Text size (px)</label>
                                <div class="flex items-center gap-3">
                                    <input id="priced-image-font" type="range"
                                        min="{{ \App\Services\Admin\ProductPricedImageService::FONT_MIN }}"
                                        max="{{ \App\Services\Admin\ProductPricedImageService::FONT_MAX }}"
                                        step="4"
                                        wire:model="pricedImageFont"
                                        class="min-w-0 flex-1">
                                    <input type="number"
                                        min="{{ \App\Services\Admin\ProductPricedImageService::FONT_MIN }}"
                                        max="{{ \App\Services\Admin\ProductPricedImageService::FONT_MAX }}"
                                        wire:model="pricedImageFont"
                                        class="w-20 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                                </div>
                                <p class="mt-1 text-xs text-[#8C8474]">
                                    {{ \App\Services\Admin\ProductPricedImageService::FONT_MIN }}–{{ \App\Services\Admin\ProductPricedImageService::FONT_MAX }} px
                                </p>
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
                            <p class="text-xs text-[#8C8474]">
                                Save &amp; {{ $product?->priced_image_path ? 'rebuild' : 'generate' }} writes the position, text size, and priced image.
                            </p>
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
