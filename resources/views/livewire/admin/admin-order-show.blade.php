<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('admin.orders.new') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Back to orders</a>
            <h1 class="mt-2 font-serif text-2xl font-semibold sm:text-3xl">Order #{{ $order->order_number }}</h1>
            <p class="text-sm text-[#8C8474]">
                Placed {{ $order->placed_at?->format('d M Y, h:i A') }}
                <span class="text-[#D8CDB6]">·</span>
                Created by {{ $order->createdBy?->name ?? '—' }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <a href="{{ route('admin.orders.print', $order) }}" target="_blank"
                title="Print label"
                aria-label="Print label"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white opacity-80 hover:bg-[#FAF6EF] hover:opacity-100">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path fill="#6B6459" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
                </svg>
            </a>
            @unless ($readOnly)
                <a href="{{ route('admin.orders.create', ['repeat' => $order->id]) }}"
                    title="Repeat order"
                    aria-label="Repeat order"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white opacity-80 hover:bg-[#FAF6EF] hover:opacity-100">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path fill="#6B6459" d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>
                    </svg>
                </a>
                <a href="{{ route('admin.orders.edit', $order) }}"
                    class="inline-flex h-9 items-center rounded-lg border border-[#E0D6C2] bg-white px-3 text-sm text-[#6B6459] hover:bg-[#FAF6EF] sm:px-4">
                    Edit order
                </a>
            @endunless
            <span class="inline-flex h-9 items-center rounded-full border border-[#E7DFCF] bg-[#FAF6EF] px-3 text-sm capitalize sm:px-4">{{ $order->status }}</span>
        </div>
    </div>

    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <div class="grid items-start gap-4 sm:gap-6 xl:grid-cols-3">
        <div class="space-y-4 sm:space-y-6 xl:col-span-2">
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Customer &amp; Delivery</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2 sm:gap-4">
                    <div><dt class="text-[#8C8474]">Name</dt><dd class="font-medium break-words">{{ $order->name }}</dd></div>
                    <div><dt class="text-[#8C8474]">Phone</dt><dd class="font-medium">{{ $order->phone }}</dd></div>
                    <div><dt class="text-[#8C8474]">Email</dt><dd class="break-all">{{ $order->email ?: '—' }}</dd></div>
                    <div><dt class="text-[#8C8474]">City</dt><dd class="break-words">{{ $order->city }}@if($order->area), {{ $order->area }}@endif</dd></div>
                    <div class="sm:col-span-2"><dt class="text-[#8C8474]">Address</dt><dd class="break-words">{{ $order->address }}</dd></div>
                    @if ($order->customer_note)
                        <div class="sm:col-span-2"><dt class="text-[#8C8474]">Customer note</dt><dd class="break-words whitespace-pre-line">{{ $order->customer_note }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Items</h2>
                <div class="space-y-3 text-sm">
                    @foreach ($order->items as $item)
                        <div class="flex items-start gap-3">
                            <x-order-product-thumb :item="$item" size="sm" class="mt-0.5" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="font-medium leading-snug break-words">{{ $item->displayName() }}</p>
                                    <span class="shrink-0 font-medium tabular-nums">&#2547; {{ number_format($item->line_total, 0) }}</span>
                                </div>
                                <p class="mt-1 text-[#8C8474] {{ $item->quantity > 1 ? 'text-rose-600 font-medium' : '' }}">
                                    Qty: {{ $item->quantity }}
                                    @if ((float) $item->price > 0 && (int) $item->quantity > 1)
                                        <span class="text-[#8C8474]">&middot; &#2547; {{ number_format($item->price, 0) }} each</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 space-y-2 border-t border-[#E7DFCF] pt-4 text-sm">
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Subtotal (revenue)</span><span class="tabular-nums">&#2547; {{ number_format($order->subtotal, 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">COGS</span><span class="tabular-nums">&#2547; {{ number_format($order->cogs(), 0) }}</span></div>
                    @foreach ($order->adjustments->where('type', 'charge') as $adj)
                        <div class="flex justify-between gap-3"><span class="text-[#6B6459]">+ {{ $adj->label }}</span><span class="tabular-nums">&#2547; {{ number_format($adj->amount, 0) }}</span></div>
                    @endforeach
                    @foreach ($order->adjustments->whereIn('type', ['discount', 'coupon']) as $adj)
                        <div class="flex justify-between gap-3 text-emerald-700"><span>− {{ $adj->label }}</span><span class="tabular-nums">&#2547; {{ number_format($adj->amount, 0) }}</span></div>
                    @endforeach
                    @if ($order->adjustments->isEmpty() && (float) $order->charge > 0)
                        <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Charge</span><span class="tabular-nums">&#2547; {{ number_format($order->charge, 0) }}</span></div>
                    @endif
                    @if ($order->adjustments->isEmpty() && (float) $order->discount > 0)
                        <div class="flex justify-between gap-3 text-emerald-700"><span>Discount</span><span class="tabular-nums">− &#2547; {{ number_format($order->discount, 0) }}</span></div>
                    @endif
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Customer delivery</span><span class="tabular-nums">&#2547; {{ number_format($order->delivery_charge, 0) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Courier cost</span><span class="tabular-nums">&#2547; {{ number_format($order->courier_charge, 0) }}</span></div>
                    @php($deliveryMargin = $order->deliveryMargin())
                    <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Delivery margin</span><span @class(['tabular-nums', 'text-rose-600' => $deliveryMargin < 0])>&#2547; {{ number_format($deliveryMargin, 0) }}</span></div>
                    <div class="flex justify-between gap-3 font-medium"><span class="text-[#6B6459]">Net revenue</span><span @class(['tabular-nums', 'text-rose-600' => $order->netRevenue() < 0])>&#2547; {{ number_format($order->netRevenue(), 0) }}</span></div>
                    <div class="flex justify-between gap-3 pt-2 text-base font-semibold border-t border-[#E7DFCF]"><span>Total (COD)</span><span class="tabular-nums">&#2547; {{ number_format($order->total, 0) }}</span></div>
                    @if ((float) $order->paid_amount > 0 || (float) $order->due_amount > 0)
                        <div class="flex justify-between gap-3 text-[#6B6459]"><span>Paid</span><span class="tabular-nums">&#2547; {{ number_format($order->paid_amount, 0) }}</span></div>
                        <div class="flex justify-between gap-3 text-[#6B6459]"><span>Due</span><span class="tabular-nums">&#2547; {{ number_format($order->due_amount, 0) }}</span></div>
                        <div class="flex justify-between gap-3 text-[#6B6459] capitalize"><span>Payment</span><span>{{ $order->payment_status }}</span></div>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Payments</h2>
                @if ($order->paymentTransactions->isNotEmpty())
                    <div class="space-y-3 text-sm mb-4">
                        @foreach ($order->paymentTransactions as $txn)
                            <div class="flex flex-wrap items-start justify-between gap-2 border-b border-[#F0EBE0] pb-3 last:border-0 last:pb-0">
                                <div>
                                    <p class="font-medium uppercase">{{ $txn->method }}
                                        @if ($txn->kind)
                                            <span class="text-[#8C8474] normal-case">&middot; {{ $txn->kind }}</span>
                                        @endif
                                        <span class="text-[#8C8474] normal-case"> &middot; {{ $txn->status }}</span>
                                    </p>
                                    <p class="text-xs text-[#8C8474]">{{ ($txn->paid_at ?? $txn->created_at)?->format('d M Y, h:i A') }}
                                        @if ($txn->receivedBy) &middot; {{ $txn->receivedBy->name }} @endif
                                    </p>
                                    @if ($txn->reference)
                                        <p class="text-xs text-[#8C8474]">Ref: {{ $txn->reference }}</p>
                                    @endif
                                    @if (is_array($txn->meta) && filled($txn->meta['note'] ?? null))
                                        <p class="text-xs text-[#6B6459] mt-0.5">{{ $txn->meta['note'] }}</p>
                                    @endif
                                </div>
                                <span class="font-semibold tabular-nums">&#2547; {{ number_format($txn->amount, 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-[#8C8474] mb-4">No payments recorded yet.</p>
                @endif

                @unless ($readOnly)
                    <form wire:submit="recordPayment" class="space-y-3 border-t border-[#E7DFCF] pt-4 text-sm">
                        <p class="text-xs font-medium uppercase tracking-wide text-[#8C8474]">Record payment</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-[#6B6459] mb-1">Amount (&#2547;)</label>
                                <input type="number" min="0" step="1" wire:model="paymentAmount"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('paymentAmount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[#6B6459] mb-1">Method</label>
                                <select wire:model="paymentMethod"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                    @foreach ($paymentMethods as $method)
                                        <option value="{{ $method->code }}">{{ $method->name }}</option>
                                    @endforeach
                                </select>
                                @error('paymentMethod') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label class="block text-[#6B6459] mb-1">Kind</label>
                            <select wire:model="paymentKind"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                <option value="advance">Advance</option>
                                <option value="partial">Partial</option>
                                <option value="settlement">Settlement</option>
                            </select>
                            @error('paymentKind') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[#6B6459] mb-1">Note (optional)</label>
                            <input type="text" wire:model="paymentNote" placeholder="Internal note"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        </div>
                        <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="recordPayment"
                            class="rounded-full bg-[#1E1E1E] px-5 py-2 text-sm font-semibold text-white hover:bg-black transition disabled:opacity-60">
                            <span wire:loading.remove wire:target="recordPayment">Record payment</span>
                            <span wire:loading wire:target="recordPayment">Saving…</span>
                        </button>
                    </form>
                @endunless
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Money history</h2>
                @if ($order->adjustmentLogs->isEmpty())
                    <p class="text-sm text-[#8C8474]">No money changes logged yet.</p>
                @else
                    <div class="space-y-3 text-sm max-h-80 overflow-y-auto">
                        @foreach ($order->adjustmentLogs as $log)
                            <div class="border-l-2 border-[#E7DFCF] pl-3">
                                <p class="font-medium text-[#1E1E1E]">
                                    @if ($log->field === 'courier_charge')
                                        Courier charge
                                    @elseif ($log->field === 'delivery_charge')
                                        Customer delivery
                                    @elseif ($log->label)
                                        {{ $log->label }}
                                    @else
                                        Adjustment
                                    @endif
                                    <span class="font-normal text-[#8C8474]">&middot; {{ str_replace('_', ' ', $log->action) }}</span>
                                </p>
                                <p class="text-xs text-[#8C8474]">
                                    {{ $log->created_at?->format('d M Y, h:i A') }}
                                    @if ($log->actor) &middot; {{ $log->actor->name }} @endif
                                    @if ($log->phase) &middot; {{ $log->phase }} @endif
                                </p>
                                @if ($log->amount_before !== null || $log->amount_after !== null)
                                    <p class="text-xs text-[#6B6459] mt-0.5 tabular-nums">
                                        &#2547; {{ number_format((float) ($log->amount_before ?? 0), 0) }}
                                        &rarr; &#2547; {{ number_format((float) ($log->amount_after ?? 0), 0) }}
                                    </p>
                                @endif
                                @if ($log->order_total_before !== null || $log->order_total_after !== null)
                                    <p class="text-xs text-[#8C8474] tabular-nums">
                                        Order total: &#2547;{{ number_format((float) ($log->order_total_before ?? 0), 0) }}
                                        &rarr; &#2547;{{ number_format((float) ($log->order_total_after ?? 0), 0) }}
                                    </p>
                                @endif
                                @if ($log->note)
                                    <p class="text-xs text-[#6B6459] mt-0.5">{{ $log->note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Order Timeline</h2>
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

        <div class="space-y-4 sm:space-y-6">
            @unless ($readOnly)
            <form wire:submit="saveStatus" class="space-y-4 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
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

            <div class="space-y-4 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Courier Dispatch</h2>

                <form wire:submit="updateCourierCharge" class="space-y-3 border-b border-[#E7DFCF] pb-4 text-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-[#8C8474]">Courier cost override</p>
                    <div>
                        <label class="block text-[#6B6459] mb-1">Courier charge (&#2547;)</label>
                        <input type="number" min="0" step="1" wire:model="courierChargeOverride"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('courierChargeOverride') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[#6B6459] mb-1">Reason (optional)</label>
                        <input type="text" wire:model="courierChargeReason" placeholder="Why this override?"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    </div>
                    <button type="submit"
                        wire:loading.attr="disabled"
                        wire:target="updateCourierCharge"
                        class="w-full rounded-full border border-[#C9A227] px-4 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF] transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="updateCourierCharge">Update courier cost</span>
                        <span wire:loading wire:target="updateCourierCharge">Saving…</span>
                    </button>
                </form>

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
                    <div class="space-y-2 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
                        <h2 class="font-semibold">Admin note</h2>
                        <p class="text-sm text-[#6B6459] whitespace-pre-line">{{ $order->admin_note }}</p>
                    </div>
                @endif
            @endunless
        </div>
    </div>
</div>
