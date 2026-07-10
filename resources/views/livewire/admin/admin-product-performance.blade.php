<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4 min-w-0">
            @php $thumb = $product->images->first()?->path @endphp
            @if ($thumb)
                <img src="{{ \App\Support\StorefrontAssets::mediumUrl($thumb) ?? \App\Support\StorefrontAssets::url($thumb) }}"
                    alt="" class="h-16 w-16 rounded-lg object-cover border border-[#E7DFCF] bg-[#FAF6EF] shrink-0">
            @endif
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Product performance</p>
                <h1 class="font-serif text-2xl sm:text-3xl font-semibold truncate">{{ $product->name }}</h1>
                <p class="mt-1 text-sm text-[#6B6459]">
                    {{ $product->sku ?: $product->slug }}
                    @if ($product->category)
                        · {{ $product->category->name }}
                    @endif
                    · Last 48 months
                </p>
            </div>
        </div>
        <a href="{{ route('admin.products.edit', $product) }}"
            class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
            Edit product
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
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
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals['sales_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($totals['sales_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($totals['delivered_volume']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($totals['delivered_value'], 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ $totals['delivered_pct'] === null ? '—' : number_format($totals['delivered_pct'], 1).'%' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
