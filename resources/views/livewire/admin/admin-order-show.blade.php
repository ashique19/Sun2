<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <a href="{{ route('admin.orders.new') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Back to orders</a>
            <h1 class="font-serif text-3xl font-semibold mt-2">Order #{{ $order->order_number }}</h1>
            <p class="text-sm text-[#8C8474]">Placed {{ $order->placed_at?->format('d M Y, h:i A') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.orders.print', $order) }}" target="_blank"
                title="Print label"
                aria-label="Print label"
                class="inline-flex items-center opacity-70 hover:opacity-100">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path fill="#6B6459" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
                </svg>
            </a>
            @unless ($readOnly)
                <a href="{{ route('admin.orders.create', ['repeat' => $order->id]) }}"
                    title="Repeat order"
                    aria-label="Repeat order"
                    class="inline-flex items-center opacity-70 hover:opacity-100">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="#6B6459" d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>
                    </svg>
                </a>
                <a href="{{ route('admin.orders.edit', $order) }}"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-1.5 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                    Edit order
                </a>
            @endunless
            <span class="rounded-full border border-[#E7DFCF] px-4 py-1 text-sm capitalize">{{ $order->status }}</span>
        </div>
    </div>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <div class="grid xl:grid-cols-3 gap-6 items-start">
        <div class="xl:col-span-2 space-y-6">
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                <h2 class="font-semibold mb-4">Customer &amp; Delivery</h2>
                <dl class="grid sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-[#8C8474]">Name</dt><dd class="font-medium">{{ $order->name }}</dd></div>
                    <div><dt class="text-[#8C8474]">Phone</dt><dd class="font-medium">{{ $order->phone }}</dd></div>
                    <div><dt class="text-[#8C8474]">Email</dt><dd>{{ $order->email ?: '—' }}</dd></div>
                    <div><dt class="text-[#8C8474]">City</dt><dd>{{ $order->city }}@if($order->area), {{ $order->area }}@endif</dd></div>
                    <div class="sm:col-span-2"><dt class="text-[#8C8474]">Address</dt><dd>{{ $order->address }}</dd></div>
                    @if ($order->customer_note)
                        <div class="sm:col-span-2"><dt class="text-[#8C8474]">Customer note</dt><dd>{{ $order->customer_note }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                <h2 class="font-semibold mb-4">Items</h2>
                <div class="space-y-3 text-sm">
                    @foreach ($order->items as $item)
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <x-order-product-thumb :item="$item" size="md" />
                                <div class="min-w-0">
                                    <p class="font-medium truncate">{{ $item->displayName() }}</p>
                                    <p class="text-[#8C8474] {{ $item->quantity > 1 ? 'text-rose-600 font-medium' : '' }}">
                                        Qty: {{ $item->quantity }}
                                    </p>
                                </div>
                            </div>
                            <span class="shrink-0 font-medium">&#2547; {{ number_format($item->line_total, 0) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-[#E7DFCF] mt-4 pt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-[#6B6459]">Subtotal</span><span>&#2547; {{ number_format($order->subtotal, 0) }}</span></div>
                    <div class="flex justify-between"><span class="text-[#6B6459]">Delivery</span><span>&#2547; {{ number_format($order->delivery_charge, 0) }}</span></div>
                    @if ($order->charge > 0)
                        <div class="flex justify-between"><span class="text-[#6B6459]">Charge</span><span>&#2547; {{ number_format($order->charge, 0) }}</span></div>
                    @endif
                    @if ($order->discount > 0)
                        <div class="flex justify-between text-emerald-700"><span>Discount</span><span>− &#2547; {{ number_format($order->discount, 0) }}</span></div>
                    @endif
                    <div class="flex justify-between font-semibold text-base pt-2"><span>Total (COD)</span><span>&#2547; {{ number_format($order->total, 0) }}</span></div>
                </div>
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                <h2 class="font-semibold mb-4">Order Timeline</h2>
                @if ($order->statusHistory->isEmpty())
                    <p class="text-sm text-[#8C8474]">No history recorded.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($order->statusHistory as $entry)
                            <div class="border-l-2 border-[#C9A227] pl-4">
                                <p class="font-medium capitalize">{{ $entry->status }}</p>
                                <p class="text-xs text-[#8C8474]">{{ $entry->created_at?->format('d M Y, h:i A') }}
                                    @if ($entry->changedBy) &middot; {{ $entry->changedBy->name }} @endif
                                </p>
                                @if ($entry->note)
                                    <p class="text-sm text-[#6B6459] mt-1">{{ $entry->note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            @unless ($readOnly)
            <form wire:submit="saveStatus" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                <h2 class="font-semibold">Manage Order</h2>
                <div>
                    <label class="block text-sm font-medium mb-1">Status</label>
                    <select wire:model="status"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @foreach (['new', 'confirmed', 'dispatched', 'delivered', 'returned', 'cancelled'] as $statusOption)
                            <option value="{{ $statusOption }}">{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Admin note</label>
                    <textarea wire:model="adminNote" rows="4"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                </div>
                <button type="submit"
                    class="w-full rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                    Save Changes
                </button>
            </form>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                <h2 class="font-semibold">Courier Dispatch</h2>

                @if ($order->courier_tracker)
                    <div class="text-sm space-y-1">
                        <p><span class="text-[#8C8474]">Courier:</span> {{ $order->courier?->name }}</p>
                        <p><span class="text-[#8C8474]">Tracking:</span> <strong>{{ $order->courier_tracker }}</strong></p>
                        @if ($order->dispatch_date)
                            <p class="text-[#8C8474]">Dispatched {{ $order->dispatch_date->format('d M Y, h:i A') }}</p>
                        @endif
                    </div>
                @elseif ($order->isDispatchable())
                    @if ($apiCouriers->isNotEmpty())
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-[#6B6459] mb-1">Dispatch via API</label>
                                <select wire:model="apiCourierSlug"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                    @foreach ($apiCouriers as $apiCourier)
                                        <option value="{{ $apiCourier->slug }}">{{ $apiCourier->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" wire:click="dispatchViaApi" wire:loading.attr="disabled"
                                class="w-full rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black transition disabled:opacity-60">
                                <span wire:loading.remove wire:target="dispatchViaApi">Dispatch via API</span>
                                <span wire:loading wire:target="dispatchViaApi">Dispatching…</span>
                            </button>
                        </div>
                    @else
                        <p class="text-sm text-[#8C8474]">No courier APIs are configured. Add credentials in <code class="text-xs">.env</code> to enable API dispatch.</p>
                    @endif

                    <div class="border-t border-[#E7DFCF] pt-4 space-y-3">
                        <p class="text-xs text-[#8C8474]">Or assign another courier manually:</p>
                        <select wire:model="courierId"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="manualTracker" placeholder="Tracking code"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                        @error('manualTracker') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="dispatchManual"
                            class="w-full rounded-full border border-[#C9A227] px-6 py-2.5 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF] transition">
                            Save Manual Dispatch
                        </button>
                    </div>
                @else
                    <p class="text-sm text-[#8C8474]">Dispatch is only available for new or confirmed orders without a tracker.</p>
                @endif
            </div>
            @else
                @if (filled($order->admin_note))
                    <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-2">
                        <h2 class="font-semibold">Admin note</h2>
                        <p class="text-sm text-[#6B6459] whitespace-pre-line">{{ $order->admin_note }}</p>
                    </div>
                @endif
            @endunless
        </div>
    </div>
</div>
