<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="font-serif text-3xl font-semibold">Coupons</h1>
        <a href="{{ route('admin.coupons.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Coupon
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Code</th>
                        <th class="px-4 py-3 font-medium">Discount</th>
                        <th class="px-4 py-3 font-medium">Min order</th>
                        <th class="px-4 py-3 font-medium">Usage</th>
                        <th class="px-4 py-3 font-medium">Schedule</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($coupons as $coupon)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="coupon-{{ $coupon->id }}">
                            <td class="px-4 py-3 font-semibold tracking-wide">{{ $coupon->code }}</td>
                            <td class="px-4 py-3">{{ $coupon->summaryLabel() }}</td>
                            <td class="px-4 py-3 tabular-nums">&#2547; {{ number_format($coupon->min_order, 0) }}</td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ number_format($coupon->used_count) }}
                                /
                                {{ $coupon->usage_limit === null ? '∞' : number_format($coupon->usage_limit) }}
                            </td>
                            <td class="px-4 py-3 text-[#6B6459] text-xs whitespace-nowrap">
                                @if ($coupon->starts_at || $coupon->ends_at)
                                    {{ $coupon->starts_at?->format('d M Y') ?? '—' }}
                                    →
                                    {{ $coupon->ends_at?->format('d M Y') ?? '—' }}
                                @else
                                    Always
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" wire:click="toggleActive({{ $coupon->id }})"
                                    class="text-xs rounded-full px-2.5 py-1 {{ $coupon->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $coupon->is_active ? 'Active' : 'Off' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.coupons.edit', $coupon) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $coupon->id }})"
                                    wire:confirm="Delete coupon {{ $coupon->code }}?"
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-[#8C8474]">No coupons yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
