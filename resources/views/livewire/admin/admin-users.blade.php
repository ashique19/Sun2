<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 class="font-serif text-3xl font-semibold">{{ $segmentLabel }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($segment === 'customers')
                <button type="button" wire:click="openMergeDuplicatesModal"
                    class="rounded-full border border-[#E0D6C2] bg-white px-5 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                    Merge duplicate phones
                </button>
            @endif
            <a href="{{ route('admin.users.create', ['role' => $roleName]) }}" wire:navigate
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                @if ($segment === 'moderators') Create Moderator
                @elseif ($segment === 'resellers') Create Reseller
                @elseif ($segment === 'admins') Create Admin
                @else Create Customer
                @endif
            </a>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        @foreach ($segments as $segmentKey => $segmentName)
            <button type="button"
                wire:click="switchSegment('{{ $segmentKey }}')"
                wire:loading.attr="disabled"
                class="rounded-full px-4 py-1.5 text-sm border transition disabled:opacity-60 {{ $segment === $segmentKey ? 'border-[#C9A227] bg-[#C9A227] text-white font-medium' : 'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                {{ $segmentName }}
            </button>
        @endforeach
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 space-y-3">
        <input type="search" wire:model.live.debounce.300ms="search"
            placeholder="{{ $segment === 'customers' ? 'Search name, phone, email, city…' : 'Search name, phone, email…' }}"
            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        @if ($segment === 'customers')
            <div class="flex flex-wrap items-end gap-3">
                <label class="block min-w-[12rem] flex-1">
                    <span class="mb-1 block text-xs font-medium text-[#6B6459]">By city</span>
                    <input type="search" wire:model.live.debounce.300ms="cityFilter" placeholder="e.g. Dhaka"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                </label>
            </div>
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
                            <td colspan="{{ $segment === 'customers' ? 7 : 6 }}" class="px-4 py-8 text-center text-[#8C8474]">
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

    {{-- x-data required so Livewire @teleport (Alpine x-teleport) mounts to body --}}
    <div x-data="{}" wire:key="admin-users-merge-modal">
        @teleport('body')
            <div wire:key="merge-duplicates-modal-host">
                @if ($mergeDuplicatesModalOpen)
                    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
                        wire:click.self="closeMergeDuplicatesModal"
                        wire:key="merge-duplicates-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Merge duplicate customer phones">
                        <div class="flex max-h-[min(90dvh,36rem)] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
                            wire:click.stop>
                            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Merge duplicate phones</h2>
                                    <p class="mt-0.5 text-xs text-[#8C8474]">
                                        Keeps the latest profile per phone, moves orders and related data, then deletes older duplicates.
                                    </p>
                                </div>
                                <button type="button" wire:click="closeMergeDuplicatesModal"
                                    class="shrink-0 rounded-full border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                                    Close
                                </button>
                            </div>

                            <div class="space-y-3 overflow-y-auto px-4 py-3 text-sm">
                                @if ($mergeDuplicatesMessage)
                                    <div @class([
                                        'rounded-lg px-4 py-3',
                                        'bg-emerald-50 text-emerald-700' => $mergeDuplicatesRemaining === 0 && $mergeDuplicatesMergedGroups > 0,
                                        'bg-[#FAF6EF] text-[#6B6459]' => ! ($mergeDuplicatesRemaining === 0 && $mergeDuplicatesMergedGroups > 0),
                                    ])>
                                        {{ $mergeDuplicatesMessage }}
                                    </div>
                                @endif

                                @if ($mergeDuplicatesMergedGroups > 0 || $mergeDuplicatesDeletedUsers > 0)
                                    <dl class="grid grid-cols-3 gap-2 text-center">
                                        <div class="rounded-lg border border-[#EFE7D6] px-2 py-3">
                                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Groups</dt>
                                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $mergeDuplicatesMergedGroups }}</dd>
                                        </div>
                                        <div class="rounded-lg border border-[#EFE7D6] px-2 py-3">
                                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Removed</dt>
                                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $mergeDuplicatesDeletedUsers }}</dd>
                                        </div>
                                        <div class="rounded-lg border border-[#EFE7D6] px-2 py-3">
                                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Orders moved</dt>
                                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $mergeDuplicatesReassignedOrders }}</dd>
                                        </div>
                                    </dl>
                                @endif

                                @if ($mergeDuplicatesSamples !== [])
                                    <ul class="space-y-1 text-xs text-[#6B6459]">
                                        @foreach ($mergeDuplicatesSamples as $sample)
                                            <li wire:key="merge-sample-{{ $loop->index }}">{{ $sample }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#EFE7D6] px-4 py-3">
                                <button type="button" wire:click="closeMergeDuplicatesModal"
                                    class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm text-[#6B6459] hover:bg-[#FAF6EF]">
                                    {{ $mergeDuplicatesRemaining === 0 ? 'Done' : 'Cancel' }}
                                </button>
                                @if ($mergeDuplicatesRemaining > 0)
                                    <button type="button"
                                        wire:click="runMergeDuplicatesBatch"
                                        wire:loading.attr="disabled"
                                        wire:target="runMergeDuplicatesBatch"
                                        @disabled($mergeDuplicatesRunning)
                                        class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60">
                                        <span wire:loading.remove wire:target="runMergeDuplicatesBatch">
                                            {{ $mergeDuplicatesRunning ? 'Merging…' : 'Merge & continue' }}
                                        </span>
                                        <span wire:loading wire:target="runMergeDuplicatesBatch">Merging…</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endteleport
    </div>
</div>
