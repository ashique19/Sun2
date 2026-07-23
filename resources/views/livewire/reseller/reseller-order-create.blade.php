<div class="min-w-0 max-w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('reseller.orders.progress') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Orders</a>
            <h1 class="font-serif text-2xl font-semibold sm:text-3xl mt-1">Create order</h1>
            <p class="text-sm text-[#8C8474] mt-0.5">Sell price must be at or above catalog price. Extra markup is added to your commission.</p>
        </div>
    </div>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4 break-words">{{ $error }}</div>
    @endif

    <form
        wire:submit="save"
        class="space-y-4 sm:space-y-6"
        x-data
        x-on:submit.capture="
            const phone = $refs.orderPhone?.value ?? '';
            const name = $refs.orderName?.value ?? '';
            const address = $refs.orderAddress?.value ?? '';
            $wire.$set('phone', phone, false);
            $wire.$set('name', name, false);
            $wire.$set('address', address, false);
        "
    >
        {{-- Customer & Delivery --}}
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="font-semibold mb-4">Customer &amp; Delivery</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 text-sm">
                <div class="sm:col-span-2">
                    <label class="block text-[#6B6459] mb-1">Phone</label>
                    <input
                        type="tel"
                        x-ref="orderPhone"
                        wire:model.live.blur="phone"
                        placeholder="01XXXXXXXXX"
                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                    >
                    @error('phone') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="phone" class="text-xs text-[#8C8474] mt-1">Looking up customer…</div>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[#6B6459] mb-1">Customer name</label>
                    <input
                        type="text"
                        x-ref="orderName"
                        wire:model="name"
                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                    >
                    @error('name') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[#6B6459] mb-1">Delivery address</label>
                    <textarea
                        x-ref="orderAddress"
                        wire:model.live.blur="address"
                        rows="2"
                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                    ></textarea>
                    @error('address') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[#6B6459] mb-1">City</label>
                    <select wire:model.live="cityId"
                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        <option value="">Select city</option>
                        @foreach ($cities as $city)
                            <option value="{{ $city->id }}">{{ $city->name }}</option>
                        @endforeach
                    </select>
                    @error('cityId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[#6B6459] mb-1">Area</label>
                    <select wire:model.live="areaId" @disabled(! $cityId)
                        class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                        <option value="">{{ $cityId ? 'Select area' : 'Select city first' }}</option>
                        @foreach ($areas as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </select>
                    @error('areaId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Order lines --}}
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="font-semibold mb-4">Order items</h2>
            @error('lines') <p class="text-rose-600 text-sm mb-3">{{ $message }}</p> @enderror

            @if ($lines === [])
                <p class="text-sm text-[#8C8474]">No products yet — search and add below.</p>
            @else
                <div class="space-y-3">
                    @foreach ($lines as $productId => $line)
                        <div wire:key="line-{{ $productId }}" class="rounded-lg border border-[#E7DFCF] p-3">
                            <div class="flex gap-3">
                                @if (! empty($line['product_image']))
                                    <img src="{{ $line['product_image'] }}" alt="{{ $line['name'] }}"
                                        class="h-14 w-14 shrink-0 rounded-md object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                @else
                                    <div class="h-14 w-14 shrink-0 rounded-md border border-[#E7DFCF] bg-[#FAF6EF] flex items-center justify-center text-xs text-[#8C8474]">No img</div>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-medium truncate">{{ $line['name'] }}</p>
                                        <button type="button" wire:click="removeLine({{ $productId }})"
                                            class="shrink-0 text-xs text-rose-600 hover:underline">Remove</button>
                                    </div>
                                    <p class="text-xs text-[#8C8474] mt-0.5">
                                        Catalog: &#2547;{{ number_format($line['base_price'], 0) }}
                                        @if ($line['commission_rate'] > 0)
                                            &middot; Commission: &#2547;{{ number_format($line['commission_rate'], 0) }}/unit
                                        @endif
                                        &middot; Stock: {{ $line['stock_quantity'] }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-[#6B6459]">Qty</label>
                                            <input type="number" min="1"
                                                wire:model.live="lines.{{ $productId }}.quantity"
                                                class="w-16 rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-sm">
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-[#6B6459]">
                                                Sell price &#2547;
                                                <span class="text-[#8C8474]">(min &#2547;{{ number_format($line['base_price'], 0) }})</span>
                                            </label>
                                            <input type="number" min="{{ $line['base_price'] }}" step="1"
                                                wire:model.live="lines.{{ $productId }}.price"
                                                class="w-24 rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-sm">
                                        </div>
                                        @error('lines.'.$productId.'.price')
                                            <p class="text-xs text-rose-600 w-full">{{ $message }}</p>
                                        @enderror
                                        <div class="ml-auto text-right shrink-0">
                                            <p class="text-sm font-medium tabular-nums">&#2547;{{ number_format($line['line_total'], 0) }}</p>
                                            @php
                                                $estComm = \App\Services\Reseller\ResellerOrderService::estimatedLineCommission(
                                                    (float) $line['price'],
                                                    (float) $line['base_price'],
                                                    (float) $line['commission_rate'],
                                                    (int) $line['quantity'],
                                                );
                                            @endphp
                                            @if ($estComm > 0)
                                                <p class="text-xs text-[#C9A227]">+&#2547;{{ number_format($estComm, 0) }} comm.</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Product search --}}
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="font-semibold mb-3">Add products</h2>
            <input type="search" wire:model.live.debounce.300ms="productSearch"
                placeholder="Type at least 2 characters to search products…"
                class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm mb-3 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">

            @if (! $searchActive)
                <p class="text-sm text-[#8C8474]">Start typing to find products.</p>
            @elseif ($searchResults->isEmpty())
                <p class="text-sm text-[#8C8474]">No products match.</p>
            @else
                <div class="divide-y divide-[#E7DFCF] border border-[#E7DFCF] rounded-lg overflow-hidden">
                    @foreach ($searchResults as $product)
                        <div wire:key="sp-{{ $product->id }}" class="flex items-center gap-3 p-3 hover:bg-[#FAF6EF]/60">
                            @php $thumb = $product->images->first()?->path @endphp
                            @if ($thumb)
                                <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="w-10 h-10 shrink-0 rounded object-cover bg-[#FAF6EF]">
                            @else
                                <div class="w-10 h-10 shrink-0 rounded bg-[#EFE7D6] flex items-center justify-center text-[10px] text-[#8C8474]">No img</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium truncate">{{ $product->name }}</p>
                                <p class="text-xs text-[#8C8474]">
                                    &#2547;{{ number_format($product->price, 0) }}
                                    @if ($product->commission > 0)
                                        &middot; Comm &#2547;{{ number_format($product->commission, 0) }}/unit
                                    @endif
                                    &middot; Stock {{ $product->stock_quantity }}
                                </p>
                            </div>
                            <button type="button" wire:click="addProduct({{ $product->id }})"
                                class="shrink-0 rounded-full bg-[#C9A227] px-3 py-1 text-xs font-medium text-white hover:bg-[#b8931f]">
                                Add
                            </button>
                        </div>
                    @endforeach
                </div>
                @if ($searchResults->hasPages())
                    <div class="pt-3">{{ $searchResults->links() }}</div>
                @endif
            @endif
        </div>

        {{-- Order summary + submit --}}
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="font-semibold mb-4">Summary</h2>
            <div class="space-y-2 text-sm mb-4">
                <div class="flex justify-between">
                    <span class="text-[#6B6459]">Subtotal</span>
                    <span class="tabular-nums font-medium">&#2547;{{ number_format($this->subtotal(), 0) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-[#6B6459]">Delivery charge</span>
                    <span class="tabular-nums">
                        @if ((float) $deliveryCharge <= 0)
                            @if ($cityId || $areaId)
                                <span class="text-emerald-700">Free</span>
                            @else
                                <span class="text-[#8C8474]">Select city/area</span>
                            @endif
                        @else
                            &#2547;{{ number_format((float) $deliveryCharge, 0) }}
                        @endif
                    </span>
                </div>
                <div class="flex justify-between border-t border-[#EFE7D6] pt-2 font-semibold">
                    <span>Total (COD)</span>
                    <span class="tabular-nums">&#2547;{{ number_format($this->total(), 0) }}</span>
                </div>
                @if ($this->estimatedTotalCommission() > 0)
                    <div class="flex justify-between rounded-lg bg-[#FAF6EF] px-3 py-2 mt-1">
                        <span class="text-[#6B6459] text-xs">Est. commission (after delivery)</span>
                        <span class="text-[#C9A227] font-semibold tabular-nums text-xs">&#2547;{{ number_format($this->estimatedTotalCommission(), 0) }}</span>
                    </div>
                @endif
            </div>

            <button type="submit"
                wire:loading.attr="disabled"
                class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Place order</span>
                <span wire:loading wire:target="save">Placing…</span>
            </button>
        </div>
    </form>
</div>
