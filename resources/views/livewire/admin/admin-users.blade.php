<div>
    <div class="mb-6 flex items-center gap-3">
        <h1 class="min-w-0 flex-1 font-serif text-3xl font-semibold">{{ $segmentLabel }}</h1>

        <div class="flex shrink-0 items-center gap-2">
            <label for="admin-users-segment" class="sr-only">User type</label>
            <select id="admin-users-segment"
                wire:change="switchSegment($event.target.value)"
                class="rounded-lg border border-[#E0D6C2] bg-white py-2 pl-3 pr-8 text-sm text-[#1E1E1E] focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                @foreach ($segments as $segmentKey => $segmentName)
                    <option value="{{ $segmentKey }}" @selected($segment === $segmentKey)>{{ $segmentName }}</option>
                @endforeach
            </select>

                            <a href="{{ route('admin.users.create', ['role' => $roleName]) }}" wire:navigate
                class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#C9A227] text-white hover:bg-[#b8931f]"
                title="{{ $createLabel }}"
                aria-label="{{ $createLabel }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-5 w-5" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                </svg>
            </a>
        </div>
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    @if ($segment === 'customers')
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @foreach ([
                'city' => 'By city',
                'orders' => 'By order count',
                'category' => 'By category',
            ] as $pillKey => $pillLabel)
                <button type="button"
                    wire:click="openAnalyticsReport('{{ $pillKey }}')"
                    wire:loading.attr="disabled"
                    wire:target="openAnalyticsReport('{{ $pillKey }}'), loadAnalyticsReport"
                    class="rounded-full px-4 py-1.5 text-sm border transition disabled:opacity-60 {{ ($analyticsModalOpen || $analyticsMinimized) && $analyticsPill === $pillKey ? 'border-[#C9A227] bg-[#C9A227] text-white font-medium' : 'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                    {{ $pillLabel }}
                </button>
            @endforeach
        </div>

        @if ($activeFilterSummary !== [])
            <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-[#6B6459]">Filtered:</span>
                @foreach ($activeFilterSummary as $summary)
                    <span class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1 text-xs font-medium text-[#1E1E1E]">{{ $summary }}</span>
                @endforeach
                <button type="button" wire:click="clearAnalyticsFilters"
                    class="text-xs font-medium text-[#C9A227] hover:underline">Clear filters</button>
            </div>
        @endif
    @endif

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search"
            placeholder="Search name, phone, email…"
            class="min-w-0 flex-1 rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        @if ($segment === 'customers')
            <button type="button"
                wire:click="exportFilteredCustomers"
                wire:loading.attr="disabled"
                wire:target="exportFilteredCustomers"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60"
                title="Export filtered customers to Excel"
                aria-label="Export filtered customers to Excel">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2" />
                </svg>
            </button>
        @endif
    </div>

    @if ($segment === 'customers' && count($selectedCustomerIds) > 0)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-[#EFE7D6] bg-[#FAF6EF] px-4 py-3 text-sm">
            <span class="font-medium text-[#1E1E1E]">{{ count($selectedCustomerIds) }} selected</span>
            <button type="button" wire:click="selectNone"
                class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1 text-xs font-medium text-[#6B6459] hover:bg-white">
                Select none
            </button>
            <button type="button" wire:click="openPromoSmsModal"
                class="rounded-full bg-[#C9A227] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#b8931f]">
                Send promotional SMS
            </button>
        </div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        @if ($segment === 'customers')
                            <th class="px-4 py-3 font-medium w-10">
                                <input type="checkbox"
                                    wire:click="toggleSelectAllOnPage(@js($pageCustomerIds))"
                                    @checked($allOnPageSelected)
                                    @disabled($users->isEmpty())
                                    class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]"
                                    title="{{ $allOnPageSelected ? 'Deselect page' : 'Select all on page' }}"
                                    aria-label="{{ $allOnPageSelected ? 'Deselect page' : 'Select all on page' }}">
                            </th>
                        @endif
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        @if ($segment === 'customers')
                            <th class="px-4 py-3 font-medium">Orders</th>
                        @endif
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Joined</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($users as $user)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="user-{{ $user->id }}">
                            @if ($segment === 'customers')
                                <td class="px-4 py-3">
                                    <input type="checkbox"
                                        wire:click="toggleCustomerSelection({{ $user->id }})"
                                        @checked(in_array((int) $user->id, $selectedCustomerIds, true))
                                        class="rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]"
                                        aria-label="Select {{ $user->name }}">
                                </td>
                            @endif
                            <td class="px-4 py-3 font-medium">
                                @if ($segment === 'customers')
                                    <a href="{{ route('admin.customers.show', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">{{ $user->name }}</a>
                                @else
                                    {{ $user->name }}
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ $user->phone }}</td>
                            <td class="px-4 py-3 text-[#6B6459]">{{ $user->email ?: '—' }}</td>
                            @if ($segment === 'customers')
                                <td class="px-4 py-3 tabular-nums">{{ $user->orders_count ?? 0 }}</td>
                            @endif
                            <td class="px-4 py-3">
                                <button type="button" wire:click="toggleActive({{ $user->id }})"
                                    @disabled((int) $user->id === (int) auth()->id())
                                    class="text-xs rounded-full px-2.5 py-1 disabled:opacity-40 {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $user->is_active ? 'Active' : 'Off' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-[#6B6459] whitespace-nowrap">{{ $user->created_at?->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                @if ($segment === 'customers')
                                    <a href="{{ route('admin.customers.show', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">View</a>
                                @endif
                                <a href="{{ route('admin.users.edit', $user) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                @if ((int) $user->id !== (int) auth()->id())
                                    <button type="button"
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Delete {{ $user->name }}?"
                                        class="text-rose-600 hover:underline">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $segment === 'customers' ? 8 : 6 }}" class="px-4 py-8 text-center text-[#8C8474]">
                                No {{ strtolower($segmentLabel) }} yet.
                                @if ($segment === 'resellers')
                                    <a href="{{ route('admin.users.create', ['role' => 'reseller']) }}" wire:navigate class="text-[#C9A227] hover:underline">Create one</a>.
                                @elseif ($segment === 'admins')
                                    <a href="{{ route('admin.users.create', ['role' => 'admin']) }}" wire:navigate class="text-[#C9A227] hover:underline">Create one</a>.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-[#E7DFCF]">{{ $users->links() }}</div>
        @endif
    </div>

    @if ($promoSmsModalOpen)
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
            wire:click.self="closePromoSmsModal"
            wire:key="promo-sms-modal"
            role="dialog"
            aria-modal="true"
            aria-label="Send promotional SMS">
            <div class="flex max-h-[min(90dvh,36rem)] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                wire:click.stop>
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div>
                        <h2 class="font-semibold text-lg">Send promotional SMS</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            {{ count($selectedCustomerIds) }} customer(s). BTRC requires promotional SMS in Bangla.
                        </p>
                    </div>
                    <button type="button" wire:click="closePromoSmsModal"
                        class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                        Close
                    </button>
                </div>

                <div class="space-y-3 overflow-y-auto px-4 py-3 text-sm">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-[#6B6459]">Message</span>
                        <textarea wire:model="promoSmsMessage" rows="5" maxlength="1000"
                            placeholder="আপনার প্রচার বার্তা লিখুন…"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                        @error('promoSmsMessage')
                            <span class="mt-1 block text-xs text-rose-600">{{ $errors->first('promoSmsMessage') }}</span>
                        @enderror
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-[#6B6459]">Campaign ID <span class="font-normal text-[#8C8474]">(optional)</span></span>
                        <input type="text" wire:model="promoSmsCampaignId" maxlength="100"
                            placeholder="MiMSMS CampaignId"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    </label>
                </div>

                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                    <button type="button" wire:click="closePromoSmsModal"
                        class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                    <button type="button"
                        wire:click="sendPromoSms"
                        wire:loading.attr="disabled"
                        wire:target="sendPromoSms"
                        @disabled($promoSmsSending)
                        class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                        <span wire:loading.remove wire:target="sendPromoSms">
                            {{ $promoSmsSending ? 'Sending…' : 'Send SMS' }}
                        </span>
                        <span wire:loading wire:target="sendPromoSms">Sending…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($segment === 'customers' && $analyticsModalOpen)
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
            wire:click.self="minimizeAnalyticsModal"
            wire:key="analytics-report-modal"
            role="dialog"
            aria-modal="true"
            aria-label="{{ $analyticsTitle }}">
            <div class="flex max-h-[min(90dvh,40rem)] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                wire:click.stop>
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-lg">{{ $analyticsTitle }}</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            @if ($analyticsLoading)
                                Building report…
                            @else
                                Click a count to filter the customers list
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" wire:click="minimizeAnalyticsModal"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#E0D6C2] text-[#1E1E1E] hover:bg-[#FAF6EF]"
                            title="Minimize"
                            aria-label="Minimize report">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4" aria-hidden="true">
                                <path stroke-linecap="round" d="M6 12h12" />
                            </svg>
                        </button>
                        <button type="button" wire:click="closeAnalyticsModal"
                            class="rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                            Close
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    @if ($analyticsLoading)
                        <div class="flex flex-col items-center justify-center gap-3 py-12 text-sm text-[#6B6459]" wire:key="analytics-loading">
                            <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-[#E0D6C2] border-t-[#C9A227]" aria-hidden="true"></span>
                            <p class="font-medium text-[#1E1E1E]">Generating report</p>
                            <p class="text-xs text-[#8C8474]">Counting customers — this stays open until ready.</p>
                            <div class="mt-2 h-1.5 w-48 overflow-hidden rounded-full bg-[#EFE7D6]">
                                <div class="h-full w-1/2 animate-pulse rounded-full bg-[#C9A227]"></div>
                            </div>
                        </div>
                    @else
                        @if (count($analyticsRows) > 8)
                            <label class="mb-3 block">
                                <span class="sr-only">Search report</span>
                                <input type="search" wire:model.live.debounce.200ms="analyticsReportSearch"
                                    placeholder="Search in report…"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                            </label>
                        @endif

                        @if ($visibleAnalyticsRows === [])
                            <p class="py-10 text-center text-sm text-[#8C8474]">
                                {{ $analyticsReportSearch !== '' ? 'No matches in this report.' : 'No data for this report yet.' }}
                            </p>
                        @else
                            <div class="space-y-2">
                                @foreach ($visibleAnalyticsRows as $row)
                                    <button type="button"
                                        wire:key="analytics-row-{{ $analyticsPill }}-{{ $row['key'] }}"
                                        wire:click="applyAnalyticsFilter('{{ $analyticsPill }}', @js($row['key']))"
                                        class="flex w-full items-center justify-between gap-3 rounded-lg border border-[#EFE7D6] bg-[#FAF6EF]/50 px-3 py-2.5 text-left text-sm hover:border-[#C9A227] hover:bg-[#FAF6EF]">
                                        <span class="min-w-0 truncate text-[#1E1E1E]">{{ $row['label'] }}</span>
                                        <span class="shrink-0 rounded-full bg-white px-2.5 py-0.5 text-sm font-semibold tabular-nums text-[#C9A227] ring-1 ring-[#E0D6C2]">
                                            {{ number_format($row['count']) }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>

                            @if ($analyticsReportSearch === '' && ! $analyticsShowAllRows && $analyticsFilteredTotal > count($visibleAnalyticsRows))
                                <button type="button"
                                    wire:click="$set('analyticsShowAllRows', true)"
                                    class="mt-3 w-full rounded-lg border border-dashed border-[#E0D6C2] px-3 py-2 text-sm font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                                    Show all {{ number_format($analyticsFilteredTotal) }}
                                </button>
                            @endif
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($segment === 'customers' && $analyticsMinimized && $analyticsPill !== '')
        <div class="fixed bottom-4 right-4 z-[70]" wire:key="analytics-minimized-dock">
            <button type="button"
                wire:click="restoreAnalyticsModal"
                class="flex items-center gap-2 rounded-full border border-[#E0D6C2] bg-white px-4 py-2.5 text-sm font-medium text-[#1E1E1E] shadow-lg hover:border-[#C9A227]">
                <span class="truncate max-w-[12rem]">{{ $analyticsTitle }}</span>
                <span class="rounded-full bg-[#FAF6EF] px-2 py-0.5 text-xs tabular-nums text-[#6B6459]">
                    {{ number_format(count($analyticsCache[$analyticsPill] ?? [])) }}
                </span>
                <span class="text-[#C9A227]" aria-hidden="true">↗</span>
            </button>
        </div>
    @endif
</div>
