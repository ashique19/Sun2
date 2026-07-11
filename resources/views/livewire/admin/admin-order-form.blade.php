<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <a href="{{ $order ? route('admin.orders.show', $order) : route('admin.orders.new') }}"
                class="text-sm text-[#C9A227] hover:underline">&larr; Back</a>
            <h1 class="font-serif text-3xl font-semibold mt-2">{{ $order ? 'Edit' : 'Create' }} Order</h1>
        </div>
        @if ($order)
            <button type="button" wire:click="delete" wire:confirm="Delete this order and restore product stock?"
                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700 hover:bg-rose-100">
                Delete order
            </button>
        @endif
    </div>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="grid xl:grid-cols-3 gap-6 items-start">
            <div class="xl:col-span-2 space-y-6">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">Customer &amp; Delivery</h2>
                    <div class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div class="sm:col-span-2">
                            <label class="block text-[#6B6459] mb-1">Phone / paste customer block</label>
                            <textarea wire:model.live.debounce.500ms="phone" rows="3"
                                placeholder="Paste name, phone, address… or type 01XXXXXXXXX"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                            @error('phone') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror

                            <div wire:loading wire:target="phone" class="text-xs text-[#8C8474] mt-2">Parsing &amp; looking up customer…</div>

                            @if ($steadfastStats)
                                <div class="mt-3 rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2 text-xs">
                                    <p class="font-medium text-[#1E1E1E]">Steadfast delivery success: {{ $steadfastStats['success_ratio'] ?? 0 }}%</p>
                                    <p class="text-[#6B6459] mt-1">
                                        Delivered {{ $steadfastStats['total_delivered'] ?? 0 }}
                                        / {{ $steadfastStats['total_parcels'] ?? 0 }}
                                        @if (($steadfastStats['total_cancelled'] ?? 0) > 0)
                                            &middot; Cancelled {{ $steadfastStats['total_cancelled'] }}
                                        @endif
                                    </p>
                                </div>
                            @elseif ($steadfastStatsError && \App\Support\PhoneNumber::isValidDisplayMobile($phone))
                                <p class="text-xs text-[#8C8474] mt-2">{{ $steadfastStatsError }}</p>
                            @endif

                            @if ($previousOrderCount > 0)
                                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="text-[#6B6459]">
                                        {{ $previousOrderCount }} previous {{ str('order')->plural($previousOrderCount) }} on this site
                                        @if (! empty($previousOrders[0]['order_number']))
                                            &middot; latest
                                            <a href="{{ route('admin.orders.show', $previousOrders[0]['id']) }}"
                                                class="text-[#C9A227] hover:underline font-medium">#{{ $previousOrders[0]['order_number'] }}</a>
                                        @endif
                                    </span>
                                    <button type="button" wire:click="openOrderHistoryModal"
                                        class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1 text-[#6B6459] hover:bg-[#FAF6EF]">
                                        View history
                                    </button>
                                </div>
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[#6B6459] mb-1">Name</label>
                            <input type="text" wire:model="name"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                            @error('name') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[#6B6459] mb-1">Address</label>
                            <textarea wire:model.live.debounce.400ms="address" rows="2"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                            @error('address') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                            @if ($addressLocationHint)
                                <p class="text-xs text-emerald-700 mt-1">{{ $addressLocationHint }}</p>
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model.live="isExchange"
                                    class="rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">
                                <span class="text-sm font-medium text-[#1E1E1E]">Exchange</span>
                            </label>
                            <p class="text-xs text-[#8C8474] mt-1">
                                Marks the order as has return and prefixes address &amp; courier note with [EXCHANGE PARCEL].
                            </p>
                        </div>
                        <div>
                            <label class="block text-[#6B6459] mb-1">City</label>
                            <select wire:model.live="cityId"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                <option value="">Select city</option>
                                @foreach ($cities as $city)
                                    <option value="{{ $city->id }}">{{ $city->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[#6B6459] mb-1">Area</label>
                            <select wire:model.live="areaId" @disabled(! $cityId)
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:opacity-50">
                                <option value="">Select area</option>
                                @foreach ($areas as $area)
                                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">Order lines</h2>
                    @error('lines') <p class="text-rose-600 text-sm mb-3">{{ $message }}</p> @enderror

                    @if ($lines === [])
                        <p class="text-sm text-[#8C8474]">No products added yet. Search below to add products.</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($lines as $productId => $line)
                                <div wire:key="line-{{ $productId }}" class="flex flex-wrap items-center gap-3 border border-[#E7DFCF] rounded-lg p-3">
                                    <div class="flex flex-1 min-w-[12rem] items-center gap-3">
                                        <a href="{{ route('admin.products.edit', $productId) }}"
                                            wire:navigate
                                            title="{{ $line['name'] }}"
                                            class="shrink-0 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227]">
                                            @if (! empty($line['product_image']))
                                                <img src="{{ $line['product_image'] }}"
                                                    alt="{{ $line['name'] }}"
                                                    class="h-16 w-16 rounded-md object-cover border border-[#E7DFCF] bg-[#FAF6EF] hover:opacity-90">
                                            @else
                                                <div class="h-16 w-16 rounded-md border border-[#E7DFCF] bg-[#FAF6EF] flex items-center justify-center text-xs text-[#8C8474]">No img</div>
                                            @endif
                                        </a>
                                        <p class="text-xs text-[#8C8474]">
                                            &#2547; {{ number_format($line['price'], 0) }} each
                                            &middot; Stock: {{ $line['stock_quantity'] }}
                                            @if ($order)
                                                (+ {{ $line['quantity'] }} on this order)
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-xs text-[#6B6459]">Qty</label>
                                        <input type="number" min="1"
                                            wire:model.live="lines.{{ $productId }}.quantity"
                                            class="w-20 rounded-lg border border-[#E0D6C2] px-2 py-1 text-sm">
                                    </div>
                                    <div class="font-medium text-sm tabular-nums">
                                        &#2547; {{ number_format($line['line_total'], 0) }}
                                    </div>
                                    <button type="button" wire:click="removeLine({{ $productId }})"
                                        class="text-xs text-rose-600 hover:underline">Remove</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">Add products</h2>

                    <div
                        x-data="{ pasteHint: '' }"
                        tabindex="0"
                        x-on:paste="
                            const items = [...($event.clipboardData?.items || [])];
                            const imageItem = items.find((item) => item.type && item.type.startsWith('image/'));
                            if (! imageItem) {
                                pasteHint = 'No image in clipboard — copy an image first, then paste here.';
                                return;
                            }
                            $event.preventDefault();
                            pasteHint = '';
                            const file = imageItem.getAsFile();
                            if (! file) return;
                            $wire.upload('pastedImage', file, () => {}, () => {}, () => {});
                        "
                        class="mb-4 rounded-lg border border-dashed border-[#E0D6C2] bg-[#FAF6EF]/50 px-4 py-3 text-sm text-[#6B6459] focus:outline-none focus:ring-1 focus:ring-[#C9A227] focus:border-[#C9A227]"
                    >
                        <p class="font-medium text-[#1E1E1E]">Product image</p>
                        <p class="text-xs mt-1">Choose a file or paste (Ctrl+V / Cmd+V). ≥90% auto-adds; 80–90% shows suggestions.</p>
                        <input type="file"
                            wire:model="pastedImage"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            class="mt-3 block w-full text-sm text-[#6B6459] file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium file:text-[#1E1E1E] hover:file:bg-[#F1EADB]"
                        >
                        <div wire:loading wire:target="pastedImage,searchByPastedImage" class="text-xs text-[#8C8474] mt-2">Matching image…</div>
                        <p class="text-xs text-amber-700 mt-2" x-text="pasteHint" x-show="pasteHint" x-cloak></p>
                        @error('pastedImage') <p class="text-rose-600 text-xs mt-2">{{ $message }}</p> @enderror
                        @if ($imageSearchError)
                            <p class="text-rose-600 text-xs mt-2">{{ $imageSearchError }}</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-3 mb-4">
                        <input type="search" wire:model.live.debounce.300ms="productSearch"
                            placeholder="Search name, SKU, price…"
                            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        <select wire:model.live="productCategory" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                            <option value="">All categories</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="productStock" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                            <option value="">Any stock</option>
                            <option value="in">In stock</option>
                            <option value="out">Out of stock</option>
                        </select>
                    </div>

                    @if (! $searchActive)
                        <p class="text-sm text-[#8C8474]">Type at least 2 characters, pick a category, or filter by stock to search products.</p>
                    @elseif ($searchResults->isEmpty())
                        <p class="text-sm text-[#8C8474]">No products match your search.</p>
                    @else
                        <div class="divide-y divide-[#E7DFCF] border border-[#E7DFCF] rounded-lg overflow-hidden">
                            @foreach ($searchResults as $product)
                                <div wire:key="search-product-{{ $product->id }}" class="flex items-center gap-3 p-3 hover:bg-[#FAF6EF]/60">
                                    @php $thumb = $product->images->first()?->path @endphp
                                    @if ($thumb)
                                        <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="w-10 h-10 rounded object-cover bg-[#FAF6EF] shrink-0">
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <p class="font-medium text-sm truncate">{{ $product->name }}</p>
                                        <p class="text-xs text-[#8C8474]">
                                            {{ $product->sku ?: $product->slug }}
                                            &middot; &#2547; {{ number_format($product->price, 0) }}
                                            &middot; Stock: {{ $product->stock_quantity }}
                                        </p>
                                    </div>
                                    <button type="button" wire:click="addProduct({{ $product->id }})"
                                        @disabled($product->stock_quantity <= 0 && ! isset($lines[$product->id]))
                                        class="shrink-0 rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#b89220] disabled:opacity-40">
                                        Add
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        @if ($searchResults->hasPages())
                            <div class="mt-3">{{ $searchResults->links() }}</div>
                        @endif
                    @endif
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 text-sm">
                    <h2 class="font-semibold">Totals</h2>
                    <div class="flex justify-between"><span class="text-[#6B6459]">Subtotal</span><span>&#2547; {{ number_format($this->subtotal(), 0) }}</span></div>
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <label class="text-[#6B6459]">Delivery</label>
                            <label class="inline-flex items-center gap-1 text-xs text-[#8C8474]">
                                <input type="checkbox" wire:model.live="autoDelivery" class="rounded border-[#C9A227] text-[#C9A227]">
                                Auto
                            </label>
                        </div>
                        <input type="number" min="0" step="1" wire:model.live="deliveryCharge" @disabled($autoDelivery)
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 disabled:bg-[#FAF6EF]">
                    </div>
                    <div>
                        <label class="block text-[#6B6459] mb-1">Charge</label>
                        <input type="number" min="0" step="1" wire:model.live="charge"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-[#6B6459] mb-1">Discount</label>
                        <input type="number" min="0" step="1" wire:model.live="discount"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                    </div>
                    <div class="flex justify-between font-semibold text-base border-t border-[#E7DFCF] pt-3">
                        <span>Total (COD)</span>
                        <span>&#2547; {{ number_format($this->total(), 0) }}</span>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                    <div>
                        <label class="block text-[#6B6459] text-sm mb-1">Admin note</label>
                        <p class="text-xs text-[#8C8474] mb-2">Visible to admins only.</p>
                        <textarea wire:model="adminNote" rows="3"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                    </div>
                    <div>
                        <label class="block text-[#6B6459] text-sm mb-1">Courier note</label>
                        <p class="text-xs text-[#8C8474] mb-2">Sent to the courier during dispatch.</p>
                        <textarea wire:model="courierNote" rows="3"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                    </div>
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-[#C9A227] px-4 py-3 text-sm font-semibold text-white hover:bg-[#b89220] transition">
                    {{ $order ? 'Save changes' : 'Create order' }}
                </button>
            </div>
        </div>
    </form>

    @if ($showOrderHistoryModal)
        <div class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <button type="button" wire:click="closeOrderHistoryModal" class="absolute inset-0 bg-black/40" aria-label="Close"></button>
            <div class="relative w-full max-w-2xl max-h-[85vh] overflow-hidden rounded-xl border border-[#E7DFCF] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-[#E7DFCF] px-5 py-4">
                    <h3 class="font-semibold">Previous orders for {{ $phone }}</h3>
                    <button type="button" wire:click="closeOrderHistoryModal" class="text-[#8C8474] hover:text-[#1E1E1E]">&times;</button>
                </div>
                <div class="overflow-y-auto max-h-[calc(85vh-4rem)]">
                    <table class="w-full text-sm">
                        <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                            <tr>
                                <th class="px-4 py-3 font-medium">Order</th>
                                <th class="px-4 py-3 font-medium">Placed</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E7DFCF]">
                            @foreach ($previousOrders as $previousOrder)
                                <tr wire:key="history-order-{{ $previousOrder['id'] }}" class="hover:bg-[#FAF6EF]/60">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.orders.show', $previousOrder['id']) }}"
                                            class="font-medium text-[#C9A227] hover:underline">#{{ $previousOrder['order_number'] }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-[#6B6459]">{{ $previousOrder['placed_at'] ?? '—' }}</td>
                                    <td class="px-4 py-3 capitalize">{{ $previousOrder['status'] }}</td>
                                    <td class="px-4 py-3">&#2547; {{ number_format($previousOrder['total'], 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($showImageMatchModal)
        <div class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <button type="button" wire:click="closeImageMatchModal" class="absolute inset-0 bg-black/40" aria-label="Close"></button>
            <div class="relative w-full max-w-xl max-h-[85vh] overflow-hidden rounded-xl border border-[#E7DFCF] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-[#E7DFCF] px-5 py-4">
                    <div>
                        <h3 class="font-semibold">Image matches</h3>
                        <p class="text-xs text-[#8C8474] mt-0.5">Showing matches at 80% or higher. Pick one, or create a new product.</p>
                    </div>
                    <button type="button" wire:click="closeImageMatchModal" class="text-[#8C8474] hover:text-[#1E1E1E]">&times;</button>
                </div>
                <div class="overflow-y-auto max-h-[calc(85vh-8rem)] p-4 space-y-3">
                    @if ($imageMatches === [])
                        <p class="text-sm text-[#8C8474]">No close catalog matches found. You can create a new product from this image.</p>
                    @else
                        @foreach ($imageMatches as $match)
                            <div wire:key="image-match-{{ $match['product_id'] }}" class="flex items-center gap-3 rounded-lg border border-[#E7DFCF] p-3">
                                @if ($match['image_url'])
                                    <img src="{{ $match['image_url'] }}" alt="" class="h-14 w-14 rounded object-cover bg-[#FAF6EF] shrink-0">
                                @endif
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm truncate">{{ $match['name'] }}</p>
                                    <p class="text-xs text-[#8C8474]">
                                        {{ $match['sku'] ?: '—' }}
                                        &middot; &#2547; {{ number_format($match['price'], 0) }}
                                        &middot; Stock: {{ $match['stock_quantity'] }}
                                    </p>
                                    <p class="text-xs font-semibold text-emerald-700 mt-1">{{ number_format($match['match_percent'], 1) }}% match</p>
                                </div>
                                <button type="button" wire:click="selectImageMatch({{ $match['product_id'] }})"
                                    class="shrink-0 rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#b89220]">
                                    Add
                                </button>
                            </div>
                        @endforeach
                    @endif
                </div>
                <div class="border-t border-[#E7DFCF] px-5 py-3 flex justify-end gap-2">
                    <button type="button" wire:click="closeImageMatchModal"
                        class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                    <button type="button" wire:click="openCreateProductFromImage"
                        class="rounded-lg border border-[#C9A227] px-3 py-1.5 text-sm text-[#C9A227] hover:bg-[#FAF6EF]">
                        Create new product
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showCreateProductModal)
        <div class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <button type="button" wire:click="closeCreateProductModal" class="absolute inset-0 bg-black/40" aria-label="Close"></button>
            <div class="relative w-full max-w-md overflow-hidden rounded-xl border border-[#E7DFCF] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-[#E7DFCF] px-5 py-4">
                    <h3 class="font-semibold">Create product from image</h3>
                    <button type="button" wire:click="closeCreateProductModal" class="text-[#8C8474] hover:text-[#1E1E1E]">&times;</button>
                </div>
                <div class="p-5 space-y-3 text-sm">
                    @if ($pastedImage)
                        <img src="{{ $pastedImage->temporaryUrl() }}" alt="" class="h-28 w-28 rounded object-cover bg-[#FAF6EF]">
                    @endif
                    <div>
                        <label class="block text-[#6B6459] mb-1">Name</label>
                        <input type="text" wire:model="newProductName"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                        @error('newProductName') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[#6B6459] mb-1">Price</label>
                            <input type="number" min="0" step="1" wire:model="newProductPrice"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                            @error('newProductPrice') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[#6B6459] mb-1">Stock</label>
                            <input type="number" min="0" wire:model="newProductStock"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                            @error('newProductStock') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="block text-[#6B6459] mb-1">Category</label>
                        <select wire:model="newProductCategoryId" class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2">
                            <option value="">Optional</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="border-t border-[#E7DFCF] px-5 py-3 flex justify-end gap-2">
                    <button type="button" wire:click="closeCreateProductModal"
                        class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                    <button type="button" wire:click="createProductFromPaste"
                        class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#b89220]">
                        Create &amp; add
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
