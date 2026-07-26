<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Admin Issues</h1>
            <p class="text-sm text-[#8C8474] mt-1">Manage and review all system issues requiring attention.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.dashboard') }}" 
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-[#6B6459] hover:text-[#1E1E1E] border border-[#E7DFCF] hover:border-[#C9A227] rounded-lg transition">
                &larr; Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-sm text-[#8C8474]">Total Issues</p>
            <p class="text-2xl font-semibold mt-1 text-[#1E1E1E]">{{ number_format($summary['total']) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-sm text-[#8C8474]">Needs Attention</p>
            <p class="text-2xl font-semibold mt-1 text-red-600">{{ number_format($summary['unresolved']) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-sm text-[#8C8474]">Resolved</p>
            <p class="text-2xl font-semibold mt-1 text-green-600">{{ number_format($summary['resolved']) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
            <p class="text-sm text-[#8C8474]">COD Mismatch</p>
            <p class="text-2xl font-semibold mt-1 text-[#1E1E1E]">
                {{ number_format($summary['by_type']['cod_mismatch']['unresolved'] ?? 0) }}
                <span class="text-sm font-normal text-[#8C8474]">/{{ number_format($summary['by_type']['cod_mismatch']['total'] ?? 0) }}</span>
            </p>
        </div>
    </div>

    <!-- Filters -->
    <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Status Filter -->
            <div>
                <label class="block text-sm font-medium text-[#6B6459] mb-2">Status</label>
                <select wire:model.live="statusFilter" 
                        class="w-full rounded-lg border border-[#E7DFCF] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:ring-1 focus:ring-[#C9A227] outline-none transition">
                    <option value="all">All Status</option>
                    <option value="unresolved">Needs Attention</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>

            <!-- Issue Type Filter -->
            <div>
                <label class="block text-sm font-medium text-[#6B6459] mb-2">Issue Type</label>
                <select wire:model.live="issueTypeFilter" 
                        class="w-full rounded-lg border border-[#E7DFCF] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:ring-1 focus:ring-[#C9A227] outline-none transition">
                    <option value="all">All Types</option>
                    @foreach($issueTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date Range -->
            <div>
                <label class="block text-sm font-medium text-[#6B6459] mb-2">From Date</label>
                <input type="date" wire:model.live="dateFrom" 
                       class="w-full rounded-lg border border-[#E7DFCF] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:ring-1 focus:ring-[#C9A227] outline-none transition">
            </div>

            <div>
                <label class="block text-sm font-medium text-[#6B6459] mb-2">To Date</label>
                <input type="date" wire:model.live="dateTo" 
                       class="w-full rounded-lg border border-[#E7DFCF] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:ring-1 focus:ring-[#C9A227] outline-none transition">
            </div>
        </div>

        <!-- Search and Actions -->
        <div class="flex items-center justify-between mt-6 pt-6 border-t border-[#EFE7D6]">
            <div class="flex-1 max-w-md">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-[#8C8474]" 
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input type="text" wire:model.live.debounce.300ms="search" 
                           placeholder="Search by title, description, or order number..."
                           class="w-full pl-10 pr-4 py-2 rounded-lg border border-[#E7DFCF] bg-white text-sm focus:border-[#C9A227] focus:ring-1 focus:ring-[#C9A227] outline-none transition">
                </div>
            </div>

            @if(count($selectedItems) > 0)
            <div class="flex items-center gap-3 ml-4">
                <span class="text-sm text-[#6B6459]">{{ count($selectedItems) }} selected</span>
                <button wire:click="markSelectedResolved" 
                        wire:confirm="Mark {{ count($selectedItems) }} selected items as resolved?"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition">
                    Mark Selected Resolved
                </button>
            </div>
            @endif
        </div>
    </div>

    <!-- Issues Table -->
    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium whitespace-nowrap w-8">
                            <input type="checkbox" 
                                   wire:model.live="selectAll"
                                   class="rounded border-[#E7DFCF] text-[#C9A227] focus:ring-[#C9A227]">
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Issue</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Order</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Type</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Created</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse($items as $item)
                    <tr class="hover:bg-[#FAF6EF]/50">
                        <td class="px-4 py-3">
                            <input type="checkbox" 
                                   wire:model.live="selectedItems"
                                   value="{{ $item->id }}"
                                   class="rounded border-[#E7DFCF] text-[#C9A227] focus:ring-[#C9A227]">
                        </td>
                        <td class="px-4 py-3">
                            <div>
                                <p class="font-medium text-[#1E1E1E]">{{ $item->title }}</p>
                                <p class="text-xs text-[#8C8474] mt-1 line-clamp-2">{{ $item->description }}</p>
                                @if($item->data && isset($item->data['expected_amount']) && isset($item->data['collected_amount']))
                                <p class="text-xs font-medium text-red-600 mt-1">
                                    Expected: ৳{{ number_format($item->data['expected_amount'], 2) }} • 
                                    Collected: ৳{{ number_format($item->data['collected_amount'], 2) }}
                                </p>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if($item->order)
                            <a href="{{ route('admin.orders.show', $item->order) }}" 
                               class="inline-flex items-center text-sm font-medium text-[#C9A227] hover:text-[#B8921F] transition">
                                #{{ $item->order->order_number }}
                            </a>
                            @else
                            <span class="text-sm text-[#8C8474]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                {{ $item->issue_type === 'cod_mismatch' ? 'bg-blue-100 text-blue-800' : '' }}
                                {{ $item->issue_type === 'address_validation' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                {{ $item->issue_type === 'payment_discrepancy' ? 'bg-purple-100 text-purple-800' : '' }}
                                {{ $item->issue_type === 'system_alert' ? 'bg-gray-100 text-gray-800' : '' }}
                                {{ $item->issue_type === 'other' ? 'bg-gray-100 text-gray-800' : '' }}">
                                {{ $item->getIssueTypeLabel() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="text-[#6B6459]">{{ $item->created_at->format('M d, Y') }}</span>
                            <span class="text-xs text-[#8C8474] block">{{ $item->created_at->format('h:i A') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if($item->isResolved())
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                Resolved
                                <span class="ml-1 text-xs">{{ $item->resolved_at->diffForHumans() }}</span>
                            </span>
                            @else
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                                Needs Attention
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($item->order)
                                <a href="{{ route('admin.orders.show', $item->order) }}" 
                                   class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-[#C9A227] hover:text-[#B8921F] border border-[#E7DFCF] hover:border-[#C9A227] rounded transition">
                                    Review
                                </a>
                                @endif
                                
                                @if(!$item->isResolved())
                                <button wire:click="markResolved({{ $item->id }})" 
                                        wire:confirm="Mark this issue as resolved?"
                                        class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded transition">
                                    Resolve
                                </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[#8C8474]">
                            @if($statusFilter === 'unresolved')
                            No unresolved issues found.
                            @elseif($statusFilter === 'resolved')
                            No resolved issues found.
                            @else
                            No issues found matching your filters.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($items->hasPages())
        <div class="px-6 py-4 border-t border-[#E7DFCF]">
            {{ $items->links() }}
        </div>
        @endif
    </div>

    <!-- Resolution Notes Modal (would need JavaScript implementation) -->
    <div x-data="{ showNotesModal: false, selectedItemId: null, resolutionNotes: '' }" 
         x-on:attention-item-resolved.window="showNotesModal = false">
        <!-- Modal would go here for adding resolution notes -->
    </div>
</div>