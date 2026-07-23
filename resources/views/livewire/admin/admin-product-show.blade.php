<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4 min-w-0">
            @php $thumb = $product->images->first()?->path @endphp
            @if ($thumb)
                <img src="{{ \App\Support\StorefrontAssets::mediumUrl($thumb) ?? \App\Support\StorefrontAssets::url($thumb) }}"
                    alt="" class="h-16 w-16 rounded-lg object-cover border border-[#E7DFCF] bg-[#FAF6EF] shrink-0">
            @endif
            <div class="min-w-0">
                <a href="{{ route('admin.products') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Products</a>
                <h1 class="font-serif text-2xl sm:text-3xl font-semibold mt-1 truncate">{{ $product->name }}</h1>
                <p class="mt-1 text-sm text-[#6B6459]">
                    {{ $product->sku ?: $product->slug }}
                    @if ($product->category)
                        · {{ $product->category->name }}
                    @endif
                    ·
                    <span class="{{ $product->is_published ? 'text-emerald-700' : 'text-[#8C8474]' }}">
                        {{ $product->is_published ? 'Published' : 'Draft' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.products.edit', $product) }}" wire:navigate
                class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b89220]">
                Edit product
            </a>
        </div>
    </div>

    <div class="grid items-start gap-4 sm:gap-6 xl:grid-cols-3 mb-6">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6 xl:col-span-2">
            <h2 class="font-semibold mb-4">Details</h2>
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-[#8C8474]">Price</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">&#2547; {{ number_format((float) $product->price, 0) }}</dd>
                </div>
                <div>
                    <dt class="text-[#8C8474]">Cost</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">&#2547; {{ number_format((float) $product->purchase_price, 0) }}</dd>
                </div>
                <div>
                    <dt class="text-[#8C8474]">Reseller commission</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">&#2547; {{ number_format((float) $product->commission, 0) }} / unit</dd>
                </div>
                <div>
                    <dt class="text-[#8C8474]">Max discount</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">
                        @if ($product->max_discount !== null)
                            &#2547; {{ number_format((float) $product->max_discount, 0) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[#8C8474]">Stock</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ number_format((int) $product->stock_quantity) }}</dd>
                </div>
                <div>
                    <dt class="text-[#8C8474]">Category</dt>
                    <dd class="mt-0.5 font-medium">{{ $product->category?->name ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="font-semibold mb-4">Images</h2>
            @if ($product->images->isEmpty())
                <p class="text-sm text-[#8C8474]">No images.</p>
            @else
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($product->images->take(6) as $image)
                        <img src="{{ \App\Support\StorefrontAssets::url($image->path) }}" alt=""
                            class="aspect-square w-full rounded-lg object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                    @endforeach
                </div>
                @if ($product->images->count() > 6)
                    <p class="mt-2 text-xs text-[#8C8474]">+{{ $product->images->count() - 6 }} more</p>
                @endif
            @endif
        </div>
    </div>

    <div class="mb-3">
        <h2 class="font-semibold">Analytics</h2>
        <p class="text-sm text-[#8C8474]">Lifetime sales, delivery, and placement channel mix.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">Orders</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($summary['order_count']) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">Units sold</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($summary['sales_volume']) }}</p>
            <p class="mt-0.5 text-xs text-[#8C8474]">&#2547; {{ number_format($summary['sales_value'], 0) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">Delivered</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($summary['delivered_volume']) }}</p>
            <p class="mt-0.5 text-xs text-[#8C8474]">
                {{ $summary['delivered_pct'] === null ? '—' : number_format($summary['delivered_pct'], 1).'%' }}
                · &#2547; {{ number_format($summary['delivered_value'], 0) }}
            </p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">Commission paid</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">&#2547; {{ number_format($summary['commission_earned'], 0) }}</p>
            <p class="mt-0.5 text-xs text-[#8C8474]">Returned {{ number_format($summary['returned_volume']) }} units</p>
        </div>
    </div>

    @if ($channels !== [])
        <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-[#E7DFCF]">
                <h3 class="font-medium">By placement channel</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                        <tr>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium text-right">Units</th>
                            <th class="px-4 py-3 font-medium text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E7DFCF]">
                        @foreach ($channels as $channel)
                            <tr class="hover:bg-[#FAF6EF]/50">
                                <td class="px-4 py-3">{{ $channel['label'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($channel['volume']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($channel['value'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="px-4 py-3 border-b border-[#E7DFCF]">
            <h3 class="font-medium">Monthly performance</h3>
            <p class="text-xs text-[#8C8474]">Last 48 months</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Month</th>
                        <th class="px-4 py-3 font-medium text-right">Sales volume</th>
                        <th class="px-4 py-3 font-medium text-right">Sales value</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered volume</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered value</th>
                        <th class="px-4 py-3 font-medium text-right">Delivered %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @foreach ($rows as $row)
                        <tr @class(['hover:bg-[#FAF6EF]/50', 'bg-[#FAF6EF]/30' => $row['sales_volume'] === 0 && $row['delivered_volume'] === 0])>
                            <td class="px-4 py-3 font-medium whitespace-nowrap">{{ $row['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['sales_volume']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['sales_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['delivered_volume']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['delivered_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ $row['delivered_pct'] === null ? '—' : number_format($row['delivered_pct'], 1).'%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-[#FAF6EF] font-semibold">
                    <tr>
                        <td class="px-4 py-3">48-month total</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($monthTotals['sales_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($monthTotals['sales_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($monthTotals['delivered_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($monthTotals['delivered_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ $monthTotals['delivered_pct'] === null ? '—' : number_format($monthTotals['delivered_pct'], 1).'%' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
