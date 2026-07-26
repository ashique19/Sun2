<div>
    <h1 class="font-serif text-3xl font-semibold mb-6">Dashboard</h1>

    <!-- Admin Attention Section -->
    <div class="mb-8 rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="px-6 py-5 border-b border-[#E7DFCF]">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-lg">Admin Attention</h2>
                    <p class="text-sm text-[#8C8474] mt-1">Issues requiring review and recently resolved items.</p>
                </div>
                @if($attentionSummary['unresolved_count'] > 0)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                    {{ $attentionSummary['unresolved_count'] }} needs attention
                </span>
                @endif
            </div>
        </div>

        <div x-data="{ activeTab: 'needsAttention' }" class="p-6">
            <!-- Tabs -->
            <div class="flex border-b border-[#E7DFCF] mb-6">
                <button @click="activeTab = 'needsAttention'" 
                    :class="activeTab === 'needsAttention' ? 'border-b-2 border-[#C9A227] text-[#1E1E1E]' : 'text-[#8C8474] hover:text-[#6B6459]'"
                    class="px-4 py-2 font-medium text-sm focus:outline-none transition">
                    Needs Attention ({{ $attentionSummary['unresolved_count'] }})
                </button>
                <button @click="activeTab = 'recentlyResolved'" 
                    :class="activeTab === 'recentlyResolved' ? 'border-b-2 border-[#C9A227] text-[#1E1E1E]' : 'text-[#8C8474] hover:text-[#6B6459]'"
                    class="px-4 py-2 font-medium text-sm focus:outline-none transition">
                    Recently Resolved ({{ $attentionSummary['recent_resolved']->count() }})
                </button>
            </div>

            <!-- Needs Attention Tab -->
            <div x-show="activeTab === 'needsAttention'" x-cloak>
                @if($attentionSummary['unresolved_count'] > 0)
                    <div class="space-y-4">
                        @foreach($attentionSummary['unresolved_items'] as $item)
                        <div class="border border-[#EFE7D6] rounded-lg p-4 hover:bg-[#FAF6EF]/50 transition">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ $item->getIssueTypeLabel() }}
                                        </span>
                                        @if($item->order)
                                        <span class="text-sm text-[#6B6459]">Order #{{ $item->order->order_number }}</span>
                                        @endif
                                    </div>
                                    <h3 class="font-medium text-[#1E1E1E] mb-1">{{ $item->title }}</h3>
                                    <p class="text-sm text-[#8C8474]">{{ $item->description }}</p>
                                    @if($item->data && isset($item->data['expected_amount']) && isset($item->data['collected_amount']))
                                    <p class="text-sm font-medium text-red-600 mt-2">
                                        Expected: ৳{{ number_format($item->data['expected_amount'], 2) }} • 
                                        Collected: ৳{{ number_format($item->data['collected_amount'], 2) }}
                                    </p>
                                    @endif
                                </div>
                                <div class="flex flex-col gap-2 ml-4">
                                    @if($item->order)
                                    <a href="{{ route('admin.orders.show', $item->order) }}" 
                                       class="inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium text-white bg-[#C9A227] hover:bg-[#B8921F] rounded transition">
                                        Review
                                    </a>
                                    @endif
                                    <button wire:click="markResolved({{ $item->id }})" 
                                            class="inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium text-[#6B6459] hover:text-[#1E1E1E] border border-[#E7DFCF] hover:border-[#C9A227] rounded transition">
                                        Mark Resolved
                                    </button>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-[#EFE7D6] text-xs text-[#8C8474]">
                                Created {{ $item->created_at->diffForHumans() }}
                            </div>
                        </div>
                        @endforeach
                    </div>
                    
                    @if($attentionSummary['unresolved_count'] > 5)
                    <div class="mt-6 text-center">
                        <a href="{{ route('admin.issues.index') }}" 
                           class="inline-flex items-center text-sm font-medium text-[#C9A227] hover:text-[#B8921F] transition">
                            View all {{ $attentionSummary['unresolved_count'] }} issues &rarr;
                        </a>
                    </div>
                    @endif
                @else
                    <div class="text-center py-8">
                        <p class="text-[#8C8474]">No issues need attention at the moment.</p>
                    </div>
                @endif
            </div>

            <!-- Recently Resolved Tab -->
            <div x-show="activeTab === 'recentlyResolved'" x-cloak>
                @if($attentionSummary['recent_resolved']->isNotEmpty())
                    <div class="space-y-4">
                        @foreach($attentionSummary['recent_resolved'] as $item)
                        <div class="border border-[#EFE7D6] rounded-lg p-4 bg-[#FAF6EF]/30">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                            {{ $item->getIssueTypeLabel() }}
                                        </span>
                                        @if($item->order)
                                        <span class="text-sm text-[#6B6459]">Order #{{ $item->order->order_number }}</span>
                                        @endif
                                        <span class="text-xs text-[#8C8474] ml-auto">Resolved {{ $item->resolved_at->diffForHumans() }}</span>
                                    </div>
                                    <h3 class="font-medium text-[#1E1E1E] mb-1">{{ $item->title }}</h3>
                                    <p class="text-sm text-[#8C8474]">{{ $item->description }}</p>
                                    @if($item->resolution_notes)
                                    <div class="mt-2 p-2 bg-white border border-[#EFE7D6] rounded text-sm">
                                        <p class="font-medium text-[#6B6459] mb-1">Resolution notes:</p>
                                        <p class="text-[#8C8474]">{{ $item->resolution_notes }}</p>
                                    </div>
                                    @endif
                                </div>
                                @if($item->order)
                                <div class="ml-4">
                                    <a href="{{ route('admin.orders.show', $item->order) }}" 
                                       class="inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium text-[#C9A227] hover:text-[#B8921F] border border-[#E7DFCF] hover:border-[#C9A227] rounded transition">
                                        View Order
                                    </a>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    
                    <div class="mt-6 text-center">
                        <a href="{{ route('admin.issues.index') }}" 
                           class="inline-flex items-center text-sm font-medium text-[#C9A227] hover:text-[#B8921F] transition">
                            View all resolved issues &rarr;
                        </a>
                    </div>
                @else
                    <div class="text-center py-8">
                        <p class="text-[#8C8474]">No recently resolved issues.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @php
        $primaryKeys = ['new', 'draft-ai', 'dispatched'];
        $primarySegments = collect($segments)->only($primaryKeys);
        $secondarySegments = collect($segments)->except($primaryKeys);
    @endphp

    <div x-data="{ moreOpen: false }" class="mb-8 space-y-3">
        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            @foreach ($primarySegments as $segmentKey => $segmentLabel)
                <a href="{{ route('admin.orders.'.$segmentKey) }}"
                    class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group">
                    <p class="text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</p>
                    <p class="text-2xl sm:text-3xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</p>
                    <p class="text-xs text-[#C9A227] mt-2 font-medium">View orders &rarr;</p>
                </a>
            @endforeach
        </div>

        @if ($secondarySegments->isNotEmpty())
            <div class="flex justify-center">
                <button type="button"
                    @click="moreOpen = ! moreOpen"
                    :aria-expanded="moreOpen.toString()"
                    aria-controls="dashboard-more-segments"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227] transition"
                    :title="moreOpen ? 'Hide other segments' : 'Show other segments'"
                    :aria-label="moreOpen ? 'Hide other segments' : 'Show other segments'">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"
                        class="transition-transform duration-200"
                        :class="moreOpen ? 'rotate-180' : ''">
                        <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
                    </svg>
                </button>
            </div>

            <div id="dashboard-more-segments"
                x-show="moreOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($secondarySegments as $segmentKey => $segmentLabel)
                    <a href="{{ route('admin.orders.'.$segmentKey) }}"
                        class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-5 hover:border-[#C9A227] hover:bg-[#FAF6EF] transition group">
                        <p class="text-sm text-[#8C8474] group-hover:text-[#6B6459]">{{ $segmentLabel }}</p>
                        <p class="text-2xl sm:text-3xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($segmentCounts[$segmentKey] ?? 0) }}</p>
                        <p class="text-xs text-[#C9A227] mt-2 font-medium">View orders &rarr;</p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="px-6 py-5 border-b border-[#E7DFCF]">
            <h2 class="font-semibold text-lg">Last 30 Days</h2>
            <p class="text-sm text-[#8C8474] mt-1">Daily order and delivery quantity and value (by order placed date).</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Date</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Order Qty</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Order Value</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Delivery Qty</th>
                        <th class="px-4 py-3 font-medium text-right whitespace-nowrap">Delivery Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($dailyTotals as $day)
                        <tr class="hover:bg-[#FAF6EF]/50">
                            <td class="px-4 py-3 whitespace-nowrap font-medium">{{ $day['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($day['order_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($day['order_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($day['delivery_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($day['delivery_value'], 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-[#8C8474]">No orders in the last 30 days.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($dailyTotals !== [])
                    <tfoot class="bg-[#FAF6EF] font-semibold border-t border-[#E7DFCF]">
                        <tr>
                            <td class="px-4 py-3">30-day total</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($periodTotals['order_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($periodTotals['order_value'], 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($periodTotals['delivery_qty']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($periodTotals['delivery_value'], 0) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
